using System.Diagnostics;
using System.Text.Json.Nodes;

namespace VideoEditor;

internal sealed record ProbeResult(double Duration, int Width, int Height, bool HasAudio, bool HasVideo, string Codec);

internal sealed class FfmpegTools
{
    private readonly AppPaths _paths;
    private string? _filterFlag;

    public FfmpegTools(AppPaths paths) => _paths = paths;

    public bool Ready => File.Exists(_paths.Ffmpeg) && File.Exists(_paths.Ffprobe);

    public string Version()
    {
        if (!File.Exists(_paths.Ffmpeg)) return "";
        var text = Run(_paths.Ffmpeg, ["-version"], 20).Output;
        var match = System.Text.RegularExpressions.Regex.Match(text, @"(?:ffmpeg|ffprobe) version\s+(\S+)", System.Text.RegularExpressions.RegexOptions.IgnoreCase);
        return match.Success ? match.Groups[1].Value : "";
    }

    public ProbeResult Probe(string path)
    {
        if (!File.Exists(_paths.Ffprobe)) throw new InvalidDataException("FFprobe is unavailable.");
        var result = Run(_paths.Ffprobe, ["-v", "error", "-print_format", "json", "-show_format", "-show_streams", path], 60);
        var data = JsonNode.Parse(result.Output) as JsonObject ?? throw new InvalidDataException("Media could not be analysed.");
        var duration = JsonUtil.Num(data["format"]?["duration"]);
        var width = 0;
        var height = 0;
        var hasAudio = false;
        var hasVideo = false;
        var codec = "";
        if (data["streams"] is JsonArray streams)
        {
            foreach (var item in streams.OfType<JsonObject>())
            {
                var type = JsonUtil.Str(item["codec_type"]);
                var attached = JsonUtil.Int(item["disposition"]?["attached_pic"]) == 1;
                if (type == "video" && !attached)
                {
                    hasVideo = true;
                    width = JsonUtil.Int(item["width"], width);
                    height = JsonUtil.Int(item["height"], height);
                    codec = JsonUtil.Str(item["codec_name"], codec);
                    if (duration <= 0) duration = JsonUtil.Num(item["duration"]);
                }
                if (type == "audio")
                {
                    hasAudio = true;
                    if (duration <= 0) duration = JsonUtil.Num(item["duration"]);
                }
            }
        }
        return new ProbeResult(duration > 0 ? duration : 0, width, height, hasAudio, hasVideo, codec);
    }

    public (int? width, int? height) ImageSize(string path)
    {
        try
        {
            using var image = System.Drawing.Image.FromFile(path);
            return (image.Width, image.Height);
        }
        catch
        {
            if (!Ready) return (null, null);
            var probe = Probe(path);
            return (probe.Width > 0 ? probe.Width : null, probe.Height > 0 ? probe.Height : null);
        }
    }

    public bool Thumbnail(string source, string destination, double duration)
    {
        if (!Ready) return false;
        Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
        var seek = duration > 0.6 ? "0.4" : "0";
        var result = Run(_paths.Ffmpeg, ["-y", "-hide_banner", "-loglevel", "error", "-ss", seek, "-i", source, "-frames:v", "1", "-vf", "scale=320:-2", destination], 60);
        return result.code == 0 && File.Exists(destination);
    }

    public bool PreviewProxy(string source, string destination)
    {
        if (!Ready) return false;
        Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
        var result = Run(_paths.Ffmpeg, [
            "-y", "-hide_banner", "-loglevel", "error", "-i", source,
            "-vf", "scale=min(960\\,iw):-2",
            "-c:v", "libx264", "-preset", "veryfast", "-crf", "28", "-pix_fmt", "yuv420p",
            "-c:a", "aac", "-b:a", "128k", "-movflags", "+faststart", destination
        ], 240);
        var ok = result.code == 0 && File.Exists(destination) && new FileInfo(destination).Length > 256;
        if (!ok && File.Exists(destination)) File.Delete(destination);
        return ok;
    }

    public string FilterFlag()
    {
        if (_filterFlag is not null) return _filterFlag;
        var cache = Path.Combine(_paths.Logs, "filter_flag.txt");
        if (File.Exists(cache))
        {
            var saved = File.ReadAllText(cache).Trim();
            if (saved is "-filter_complex_script" or "-/filter_complex") return _filterFlag = saved;
        }
        var help = Run(_paths.Ffmpeg, ["-hide_banner", "-h", "long"], 20).Output;
        _filterFlag = help.Contains("filter_complex_script", StringComparison.Ordinal) ? "-filter_complex_script" : "-/filter_complex";
        File.WriteAllText(cache, _filterFlag);
        return _filterFlag;
    }

    public static (int code, string Output) Run(string file, IReadOnlyList<string> args, int timeoutSeconds)
    {
        var info = new ProcessStartInfo(file)
        {
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };
        foreach (var arg in args) info.ArgumentList.Add(arg);
        using var process = Process.Start(info) ?? throw new InvalidOperationException("Export failed.");
        var stdout = process.StandardOutput.ReadToEndAsync();
        var stderr = process.StandardError.ReadToEndAsync();
        if (!process.WaitForExit(timeoutSeconds * 1000))
        {
            try { process.Kill(true); } catch { /* already gone */ }
            throw new InvalidOperationException("Processing timed out.");
        }
        process.WaitForExit();
        return (process.ExitCode, stdout.GetAwaiter().GetResult() + "\n" + stderr.GetAwaiter().GetResult());
    }
}
