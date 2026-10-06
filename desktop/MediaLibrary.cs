using System.Text.Json.Nodes;

namespace VideoEditor;

internal sealed class MediaLibrary
{
    private readonly AppPaths _paths;
    private static readonly HashSet<string> VideoExt = ["mp4", "webm", "mov", "wmv"];
    private static readonly HashSet<string> ImageExt = ["jpg", "jpeg", "png", "webp"];
    private static readonly HashSet<string> AudioExt = ["mp3", "wav", "aac", "m4a"];
    private static readonly HashSet<string> VideoMime = ["video/mp4", "video/webm", "video/quicktime", "video/x-ms-wmv", "video/x-ms-asf", "video/x-msvideo", "application/octet-stream"];
    private static readonly HashSet<string> ImageMime = ["image/jpeg", "image/png", "image/webp"];
    private static readonly HashSet<string> AudioMime = ["audio/mpeg", "audio/mp3", "audio/wav", "audio/x-wav", "audio/wave", "audio/aac", "audio/x-aac", "audio/mp4", "audio/x-m4a", "audio/m4a", "application/octet-stream"];

    public const long MaxVideo = 512L * 1024 * 1024;
    public const long MaxImage = 25L * 1024 * 1024;
    public const long MaxAudio = 80L * 1024 * 1024;

    public MediaLibrary(AppPaths paths) => _paths = paths;

    public List<JsonObject> List()
    {
        var dir = Path.Combine(_paths.Upload, "meta");
        var items = new List<JsonObject>();
        if (!Directory.Exists(dir)) return items;
        foreach (var file in Directory.GetFiles(dir, "*.json"))
        {
            var data = Read(file);
            if (data?["id"] is null) continue;
            items.Add(Public(data));
        }
        items.Sort((a, b) => string.Compare(JsonUtil.Str(b["createdAt"]), JsonUtil.Str(a["createdAt"]), StringComparison.Ordinal));
        return items;
    }

    public JsonObject? Load(string id)
    {
        if (!JsonUtil.SafeId(id)) return null;
        return Read(Path.Combine(_paths.Upload, "meta", id + ".json"));
    }

    public void Delete(JsonObject media)
    {
        foreach (var field in new[] { "file", "thumb", "preview" })
        {
            var relative = JsonUtil.Str(media[field]);
            if (relative.Length == 0) continue;
            try
            {
                var path = Absolute(media, field);
                if (File.Exists(path)) File.Delete(path);
            }
            catch { /* leave a stale file rather than throwing away the meta delete */ }
        }
        var id = JsonUtil.Str(media["id"]);
        var meta = Path.Combine(_paths.Upload, "meta", id + ".json");
        if (File.Exists(meta)) File.Delete(meta);
    }

    public string Absolute(JsonObject media, string field = "file")
    {
        var relative = JsonUtil.Str(media[field]).Replace('\\', '/');
        if (!System.Text.RegularExpressions.Regex.IsMatch(relative, @"^(videos|images|audio|previews|thumbs)/[a-f0-9]{16}\.[a-z0-9]+$"))
        {
            throw new InvalidDataException("Invalid media path.");
        }
        var full = Path.GetFullPath(Path.Combine(_paths.Upload, relative.Replace('/', Path.DirectorySeparatorChar)));
        var root = Path.GetFullPath(_paths.Upload) + Path.DirectorySeparatorChar;
        if (!full.StartsWith(root, StringComparison.OrdinalIgnoreCase) || !File.Exists(full))
        {
            throw new InvalidDataException("Missing media.");
        }
        return full;
    }

    public JsonObject SaveUpload(string originalName, string tempPath, long size, FfmpegTools tools)
    {
        var extension = Path.GetExtension(originalName).TrimStart('.').ToLowerInvariant();
        var kind = Kind(extension) ?? throw new InvalidDataException("Unsupported file type.");
        var limit = kind switch { "video" => MaxVideo, "audio" => MaxAudio, _ => MaxImage };
        if (size <= 0) throw new InvalidDataException("Upload failed.");
        if (size > limit) throw new InvalidDataException("File is too large.");
        var mime = Sniff(tempPath, extension);
        if (mime.Contains("php", StringComparison.Ordinal) || mime.Contains("executable", StringComparison.Ordinal))
        {
            throw new InvalidDataException("Unsupported file type.");
        }
        var allowed = kind switch { "video" => VideoMime, "image" => ImageMime, _ => AudioMime };
        if (!allowed.Contains(mime) || (mime == "application/octet-stream" && kind == "image"))
        {
            throw new InvalidDataException("Unsupported file type.");
        }

        var id = JsonUtil.Id();
        var storedExt = extension == "jpeg" ? "jpg" : extension;
        var folder = kind switch { "video" => "videos", "image" => "images", _ => "audio" };
        var relative = folder + "/" + id + "." + storedExt;
        var destination = Path.Combine(_paths.Upload, relative.Replace('/', Path.DirectorySeparatorChar));
        Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
        File.Copy(tempPath, destination, true);

        try
        {
            double? duration = null;
            int? width = null;
            int? height = null;
            var hasAudio = false;
            var hasVideo = false;
            var thumb = "";
            var preview = "";
            var codec = "";

            if (kind == "image")
            {
                var info = tools.ImageSize(destination);
                width = info.width;
                height = info.height;
                if (width is null or <= 0 || height is null or <= 0) throw new InvalidDataException("Media could not be analysed.");
            }
            else
            {
                if (!tools.Ready) throw new InvalidDataException("FFmpeg is unavailable.");
                var probe = tools.Probe(destination);
                if (kind == "video" && !probe.HasVideo) throw new InvalidDataException("Media could not be analysed.");
                if (kind == "audio" && !probe.HasAudio) throw new InvalidDataException("Media could not be analysed.");
                if (probe.Duration < 0.1) throw new InvalidDataException("Media could not be analysed.");
                duration = probe.Duration;
                width = probe.Width > 0 ? probe.Width : null;
                height = probe.Height > 0 ? probe.Height : null;
                hasAudio = probe.HasAudio;
                hasVideo = probe.HasVideo;
                codec = probe.Codec;
                if (kind == "video")
                {
                    var thumbRel = "thumbs/" + id + ".jpg";
                    var thumbPath = Path.Combine(_paths.Upload, thumbRel.Replace('/', Path.DirectorySeparatorChar));
                    if (tools.Thumbnail(destination, thumbPath, probe.Duration)) thumb = thumbRel;
                    var safe = storedExt is "mp4" or "webm" && codec.ToLowerInvariant() is "h264" or "vp8" or "vp9" or "av1";
                    if (!safe)
                    {
                        var previewRel = "previews/" + id + ".mp4";
                        var previewPath = Path.Combine(_paths.Upload, previewRel.Replace('/', Path.DirectorySeparatorChar));
                        if (tools.PreviewProxy(destination, previewPath)) preview = previewRel;
                    }
                }
            }

            var url = "uploads/" + relative;
            var media = new JsonObject
            {
                ["id"] = id,
                ["name"] = JsonUtil.DisplayName(originalName),
                ["type"] = kind,
                ["ext"] = storedExt,
                ["mime"] = mime,
                ["size"] = size,
                ["duration"] = duration,
                ["width"] = width,
                ["height"] = height,
                ["hasAudio"] = hasAudio,
                ["hasVideo"] = hasVideo,
                ["file"] = relative,
                ["thumb"] = thumb,
                ["preview"] = preview,
                ["url"] = url,
                ["previewUrl"] = preview.Length > 0 ? "uploads/" + preview : url,
                ["thumbUrl"] = thumb.Length > 0 ? "uploads/" + thumb : "",
                ["createdAt"] = DateTimeOffset.Now.ToString("o"),
            };
            File.WriteAllText(Path.Combine(_paths.Upload, "meta", id + ".json"), media.ToJsonString());
            return Public(media);
        }
        catch
        {
            if (File.Exists(destination)) File.Delete(destination);
            throw;
        }
    }

    public static JsonObject Public(JsonObject media)
    {
        return new JsonObject
        {
            ["id"] = JsonUtil.Str(media["id"]),
            ["name"] = JsonUtil.Str(media["name"]),
            ["type"] = JsonUtil.Str(media["type"]),
            ["ext"] = JsonUtil.Str(media["ext"]),
            ["size"] = (long)JsonUtil.Num(media["size"]),
            ["duration"] = media["duration"] is null ? null : JsonUtil.Num(media["duration"]),
            ["width"] = media["width"] is null ? null : JsonUtil.Int(media["width"]),
            ["height"] = media["height"] is null ? null : JsonUtil.Int(media["height"]),
            ["hasAudio"] = JsonUtil.Bool(media["hasAudio"]),
            ["hasVideo"] = JsonUtil.Bool(media["hasVideo"]),
            ["url"] = JsonUtil.Str(media["url"]),
            ["previewUrl"] = JsonUtil.Str(media["previewUrl"], JsonUtil.Str(media["url"])),
            ["thumbUrl"] = JsonUtil.Str(media["thumbUrl"]),
            ["createdAt"] = JsonUtil.Str(media["createdAt"]),
        };
    }

    private static string? Kind(string extension)
    {
        if (VideoExt.Contains(extension)) return "video";
        if (ImageExt.Contains(extension)) return "image";
        if (AudioExt.Contains(extension)) return "audio";
        return null;
    }

    private static JsonObject? Read(string path)
    {
        if (!File.Exists(path)) return null;
        return JsonNode.Parse(File.ReadAllText(path)) as JsonObject;
    }

    private static string Sniff(string path, string extension)
    {
        var bytes = new byte[16];
        using (var stream = File.OpenRead(path))
        {
            stream.Read(bytes, 0, bytes.Length);
        }
        if (bytes[0] == 0xFF && bytes[1] == 0xD8) return "image/jpeg";
        if (bytes[0] == 0x89 && bytes[1] == 0x50 && bytes[2] == 0x4E && bytes[3] == 0x47) return "image/png";
        if (bytes[0] == 'R' && bytes[1] == 'I' && bytes[2] == 'F' && bytes[3] == 'F')
        {
            return extension is "webp" ? "image/webp" : "audio/wav";
        }
        if (bytes[0] == 'I' && bytes[1] == 'D' && bytes[2] == '3' || bytes[0] == 0xFF && (bytes[1] & 0xE0) == 0xE0) return "audio/mpeg";
        var ascii = System.Text.Encoding.ASCII.GetString(bytes);
        if (ascii.Contains("ftyp")) return extension is "m4a" ? "audio/mp4" : "video/mp4";
        if (ascii.StartsWith("\u001aE\u00df\u00a3") || bytes[0] == 0x1A && bytes[1] == 0x45 && bytes[2] == 0xDF && bytes[3] == 0xA3) return "video/webm";
        if (bytes[0] == 0x30 && bytes[1] == 0x26) return "video/x-ms-wmv";
        return extension switch
        {
            "mp4" => "video/mp4",
            "webm" => "video/webm",
            "mov" => "video/quicktime",
            "wmv" => "video/x-ms-wmv",
            "mp3" => "audio/mpeg",
            "wav" => "audio/wav",
            "aac" => "audio/aac",
            "m4a" => "audio/mp4",
            _ => "application/octet-stream",
        };
    }
}
