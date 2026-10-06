using System.Diagnostics;
using System.Text.Json.Nodes;
using System.Text.RegularExpressions;

namespace VideoEditor;

internal sealed class ExportService
{
    private readonly AppPaths _paths;
    private readonly MediaLibrary _library;
    private readonly FfmpegTools _tools;

    public ExportService(AppPaths paths, MediaLibrary library, FfmpegTools tools)
    {
        _paths = paths;
        _library = library;
        _tools = tools;
    }

    public JsonObject Start(JsonObject project)
    {
        Cleanup();
        if (!File.Exists(_paths.Ffmpeg)) throw new InvalidDataException("FFmpeg is unavailable.");
        if (!File.Exists(_paths.Ffprobe)) throw new InvalidDataException("FFprobe is unavailable.");
        var id = JsonUtil.Id();
        var dir = Path.Combine(_paths.Export, "jobs", id);
        Directory.CreateDirectory(dir);
        var plan = FilterGraph.Build(project, dir, _library);
        var filterPath = Path.Combine(dir, "filter.txt");
        File.WriteAllText(filterPath, plan.Filter);
        var output = Path.Combine(_paths.Export, id + ".mp4");
        var progress = Path.Combine(dir, "progress.txt");
        var high = JsonUtil.Str(project["exportMode"]) == "high";
        var args = new List<string> { "-y", "-hide_banner", "-loglevel", "warning", "-progress", progress, "-nostats" };
        args.AddRange(plan.Args);
        args.Add(_tools.FilterFlag());
        args.Add(filterPath);
        args.AddRange([
            "-map", "[vout]", "-map", "[aout]",
            "-c:v", "libx264", "-preset", high ? "medium" : "veryfast", "-crf", high ? "18" : "26",
            "-pix_fmt", "yuv420p", "-color_range", "tv", "-movflags", "+faststart",
            "-c:a", "aac", "-b:a", high ? "192k" : "128k", "-ar", "48000", "-ac", "2",
            "-t", JsonUtil.Ff(plan.Duration), output
        ]);
        var now = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
        var job = new JsonObject
        {
            ["id"] = id,
            ["status"] = "running",
            ["progress"] = 0,
            ["processedTime"] = 0,
            ["message"] = "Encoding video",
            ["error"] = "",
            ["duration"] = plan.Duration,
            ["name"] = JsonUtil.Str(project["name"], "Untitled Project"),
            ["createdAt"] = now,
            ["startedAt"] = now,
            ["finishedAt"] = null,
        };
        Save(id, job);
        Spawn(args, dir);
        return Public(job);
    }

    public JsonObject Status(string id)
    {
        var job = ReadJob(id) ?? throw new InvalidDataException("Export job not found.");
        if (JsonUtil.Str(job["status"]) == "running")
        {
            job = Refresh(job);
            Save(id, job);
        }
        return Public(job);
    }

    public (string Path, string Name) Download(string id)
    {
        var job = ReadJob(id) ?? throw new InvalidDataException("Export job not found.");
        if (JsonUtil.Str(job["status"]) != "done")
        {
            job = Refresh(job);
            if (JsonUtil.Str(job["status"]) != "done") throw new InvalidDataException("Export job not found.");
        }
        var path = Path.Combine(_paths.Export, id + ".mp4");
        if (!File.Exists(path)) throw new InvalidDataException("Export job not found.");
        return (path, DownloadName(JsonUtil.Str(job["name"], "video")));
    }

    private void Spawn(List<string> args, string dir)
    {
        var log = Path.Combine(dir, "log.txt");
        var exitFile = Path.Combine(dir, "exitcode.txt");
        var bat = Path.Combine(dir, "run.bat");
        var command = string.Join(" ", new[] { _paths.Ffmpeg }.Concat(args).Select(Quote));
        var script = "@echo off\r\n" + command + " 1> " + Quote(log) + " 2>&1\r\n"
            + "echo %ERRORLEVEL%> " + Quote(exitFile) + "\r\n";
        File.WriteAllText(bat, script);
        var info = new ProcessStartInfo
        {
            FileName = "cmd.exe",
            Arguments = "/c start \"\" /b " + Quote(bat),
            UseShellExecute = false,
            CreateNoWindow = true,
            WorkingDirectory = dir,
        };
        using var process = Process.Start(info) ?? throw new InvalidOperationException("Export failed.");
        process.WaitForExit(5000);
    }

    private static string Quote(string value) => "\"" + value.Replace("\"", "\"\"") + "\"";

    private JsonObject Refresh(JsonObject job)
    {
        var id = JsonUtil.Str(job["id"]);
        var dir = Path.Combine(_paths.Export, "jobs", id);
        var progress = ParseProgress(Path.Combine(dir, "progress.txt"));
        var duration = Math.Max(0.1, JsonUtil.Num(job["duration"], 0.1));
        job["processedTime"] = progress.Time;
        var percent = Math.Min(99, progress.Time / duration * 100);
        var output = Path.Combine(_paths.Export, id + ".mp4");
        int? exitCode = null;
        var exitFile = Path.Combine(dir, "exitcode.txt");
        if (File.Exists(exitFile) && int.TryParse(File.ReadAllText(exitFile).Trim(), out var code)) exitCode = code;
        var started = (long)JsonUtil.Num(job["startedAt"], DateTimeOffset.UtcNow.ToUnixTimeSeconds());
        var elapsed = DateTimeOffset.UtcNow.ToUnixTimeSeconds() - started;
        var logPath = Path.Combine(dir, "log.txt");
        var progressExists = File.Exists(Path.Combine(dir, "progress.txt"));
        if (exitCode is null && elapsed > 30 && !progressExists && (!File.Exists(logPath) || new FileInfo(logPath).Length == 0))
        {
            Fail(job, 0);
            return job;
        }
        var ready = File.Exists(output) && new FileInfo(output).Length > 1024;
        if ((exitCode == 0 && ready) || (progress.End && ready && exitCode is null))
        {
            job["status"] = "done";
            job["progress"] = 100;
            job["message"] = "Export complete";
            job["error"] = "";
            job["finishedAt"] = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
            return job;
        }
        if (exitCode is not null && exitCode != 0)
        {
            Fail(job, percent);
            if (File.Exists(output)) File.Delete(output);
            return job;
        }
        if (progress.End && !ready && exitCode == 0)
        {
            Fail(job, percent);
            return job;
        }
        job["status"] = "running";
        job["progress"] = Math.Round(percent, 1);
        job["message"] = "Encoding video";
        return job;
    }

    private static void Fail(JsonObject job, double percent)
    {
        job["status"] = "error";
        job["progress"] = (int)Math.Round(percent);
        job["message"] = "Export failed";
        job["error"] = "Export failed.";
        job["finishedAt"] = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
    }

    private static (double Time, bool End) ParseProgress(string path)
    {
        if (!File.Exists(path)) return (0, false);
        var text = File.ReadAllText(path);
        var matches = Regex.Matches(text, @"out_time=(\d+):(\d+):(\d+(?:\.\d+)?)");
        var time = 0.0;
        if (matches.Count > 0)
        {
            var last = matches[^1];
            time = int.Parse(last.Groups[1].Value) * 3600 + int.Parse(last.Groups[2].Value) * 60 + double.Parse(last.Groups[3].Value, System.Globalization.CultureInfo.InvariantCulture);
        }
        var flags = Regex.Matches(text, @"progress=(\w+)");
        var end = flags.Count > 0 && flags[^1].Groups[1].Value == "end";
        return (time, end);
    }

    private JsonObject Public(JsonObject job)
    {
        var id = JsonUtil.Str(job["id"]);
        var status = JsonUtil.Str(job["status"], "queued");
        var started = (long)JsonUtil.Num(job["startedAt"], DateTimeOffset.UtcNow.ToUnixTimeSeconds());
        return new JsonObject
        {
            ["id"] = id,
            ["status"] = status,
            ["progress"] = JsonUtil.Num(job["progress"]),
            ["processedTime"] = JsonUtil.Num(job["processedTime"]),
            ["elapsed"] = Math.Max(0, DateTimeOffset.UtcNow.ToUnixTimeSeconds() - started),
            ["message"] = JsonUtil.Str(job["message"]),
            ["error"] = JsonUtil.Str(job["error"]),
            ["downloadUrl"] = status == "done" ? "api/download.php?id=" + Uri.EscapeDataString(id) : "",
        };
    }

    private JsonObject? ReadJob(string id)
    {
        if (!JsonUtil.SafeId(id)) return null;
        var path = Path.Combine(_paths.Export, "jobs", id, "job.json");
        return File.Exists(path) ? JsonNode.Parse(File.ReadAllText(path)) as JsonObject : null;
    }

    private void Save(string id, JsonObject job)
    {
        var path = Path.Combine(_paths.Export, "jobs", id, "job.json");
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        File.WriteAllText(path, job.ToJsonString());
    }

    private void Cleanup()
    {
        var root = Path.Combine(_paths.Export, "jobs");
        if (!Directory.Exists(root)) return;
        var cutoff = DateTime.UtcNow.AddHours(-48);
        foreach (var dir in Directory.GetDirectories(root))
        {
            if (Directory.GetLastWriteTimeUtc(dir) > cutoff) continue;
            var id = Path.GetFileName(dir);
            var mp4 = Path.Combine(_paths.Export, id + ".mp4");
            if (File.Exists(mp4)) File.Delete(mp4);
            Directory.Delete(dir, true);
        }
    }

    private static string DownloadName(string name)
    {
        var cleaned = Regex.Replace(name, "[^A-Za-z0-9._-]+", "_").Trim('.', '_');
        if (cleaned.Length == 0) cleaned = "video";
        if (!cleaned.EndsWith(".mp4", StringComparison.OrdinalIgnoreCase)) cleaned += ".mp4";
        return cleaned.Length > 80 ? cleaned[..80] : cleaned;
    }

}
