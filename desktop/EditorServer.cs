using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Http;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging;
using System.Net;
using System.Net.Sockets;
using System.Text.Json;
using System.Text.Json.Nodes;
using System.Text.RegularExpressions;

namespace VideoEditor;

internal sealed class EditorServer : IDisposable
{
    private readonly WebApplication _app;
    public string Url { get; }

    private EditorServer(WebApplication app, string url)
    {
        _app = app;
        Url = url;
    }

    public static EditorServer Start(AppPaths paths)
    {
        var port = FreePort();
        var url = "http://127.0.0.1:" + port;
        var options = new WebApplicationOptions { Args = [], ContentRootPath = paths.Root };
        var builder = WebApplication.CreateBuilder(options);
        builder.Logging.ClearProviders();
        builder.WebHost.UseUrls(url);
        builder.WebHost.ConfigureKestrel(kestrel => kestrel.Limits.MaxRequestBodySize = 540L * 1024 * 1024);
        builder.Services.Configure<Microsoft.AspNetCore.Http.Features.FormOptions>(form => form.MultipartBodyLengthLimit = 540L * 1024 * 1024);
        var app = builder.Build();
        var library = new MediaLibrary(paths);
        var tools = new FfmpegTools(paths);
        var exports = new ExportService(paths, library, tools);
        app.Use(async (context, next) =>
        {
            var result = TryStatic(paths, context.Request.Path.Value ?? "");
            if (result is null)
            {
                await next();
                return;
            }
            await result.ExecuteAsync(context);
        });
        Map(app, paths, library, tools, exports);
        app.StartAsync().GetAwaiter().GetResult();
        return new EditorServer(app, url + "/");
    }

    public void Stop() => _app.StopAsync().GetAwaiter().GetResult();
    public void Dispose() => Stop();

    private static void Map(WebApplication app, AppPaths paths, MediaLibrary library, FfmpegTools tools, ExportService exports)
    {
        app.MapGet("/", () => Results.Content(Index(paths), "text/html; charset=utf-8"));
        app.MapGet("/index.php", () => Results.Content(Index(paths), "text/html; charset=utf-8"));
        app.MapGet("/api/health.php", () =>
        {
            var ready = tools.Ready;
            return Ok(ready ? "FFmpeg: Ready" : "FFmpeg is unavailable.", new JsonObject
            {
                ["ffmpeg"] = File.Exists(paths.Ffmpeg),
                ["ffprobe"] = File.Exists(paths.Ffprobe),
                ["version"] = ready ? tools.Version() : "",
                ["limits"] = new JsonObject
                {
                    ["video"] = MediaLibrary.MaxVideo,
                    ["image"] = MediaLibrary.MaxImage,
                    ["audio"] = MediaLibrary.MaxAudio,
                    ["phpUpload"] = MediaLibrary.MaxVideo,
                },
                ["composition"] = new JsonObject { ["width"] = 1920, ["height"] = 1080, ["fps"] = 30 },
            });
        });
        app.MapGet("/api/media.php", () => Ok("Media library", new JsonObject { ["media"] = new JsonArray(library.List().Select(item => item).ToArray()) }));
        app.MapPost("/api/media.php", async (HttpRequest request) =>
        {
            var body = await ReadBody(request);
            if (JsonUtil.Str(body["action"]) != "delete") return Err("Invalid project data.");
            var media = library.Load(JsonUtil.Str(body["id"]));
            if (media is null) return Err("Media not found.", 404);
            library.Delete(media);
            return Ok("Media deleted", new JsonObject { ["id"] = JsonUtil.Str(body["id"]) });
        });
        app.MapPost("/api/upload.php", async (HttpRequest request) =>
        {
            if (!request.HasFormContentType) return Err("Upload failed.");
            var form = await request.ReadFormAsync();
            var file = form.Files.GetFile("file");
            if (file is null || file.Length == 0) return Err("Upload failed.");
            var temp = Path.Combine(Path.GetTempPath(), "ve-" + Guid.NewGuid().ToString("n"));
            await using (var stream = File.Create(temp))
            {
                await file.CopyToAsync(stream);
            }
            try
            {
                var media = library.SaveUpload(file.FileName, temp, file.Length, tools);
                return Ok("Upload successful", new JsonObject { ["media"] = media });
            }
            catch (InvalidDataException ex)
            {
                return Err(Safe(ex.Message));
            }
            finally
            {
                if (File.Exists(temp)) File.Delete(temp);
            }
        });
        app.MapPost("/api/export.php", async (HttpRequest request) =>
        {
            try
            {
                var project = ProjectSanitizer.Sanitize(await ReadBody(request), library);
                return Ok("Export started", new JsonObject { ["job"] = exports.Start(project) });
            }
            catch (InvalidDataException ex)
            {
                return Err(Safe(ex.Message));
            }
            catch (Exception)
            {
                return Err("Export failed.");
            }
        });
        app.MapGet("/api/export_status.php", (string? id) =>
        {
            try { return Ok("Export status", new JsonObject { ["job"] = exports.Status(id ?? "") }); }
            catch (InvalidDataException ex) { return Err(Safe(ex.Message), 404); }
            catch (Exception) { return Err("Export job not found.", 404); }
        });
        app.MapGet("/api/download.php", (string? id) =>
        {
            try
            {
                var file = exports.Download(id ?? "");
                return Results.File(file.Path, "video/mp4", file.Name);
            }
            catch (InvalidDataException ex) { return Err(Safe(ex.Message), 404); }
        });
    }

    private static IResult? TryStatic(AppPaths paths, string path)
    {
        var relative = path.Replace('\\', '/').TrimStart('/');
        if (relative.Length == 0 || relative.Contains("..", StringComparison.Ordinal) || relative.StartsWith("includes/", StringComparison.OrdinalIgnoreCase) || relative.StartsWith("bin/", StringComparison.OrdinalIgnoreCase) || relative.StartsWith("logs/", StringComparison.OrdinalIgnoreCase) || relative.StartsWith("exports/", StringComparison.OrdinalIgnoreCase))
        {
            return null;
        }
        if (!relative.StartsWith("assets/", StringComparison.OrdinalIgnoreCase) && !relative.StartsWith("uploads/", StringComparison.OrdinalIgnoreCase))
        {
            return null;
        }
        var full = Path.GetFullPath(Path.Combine(paths.Root, relative.Replace('/', Path.DirectorySeparatorChar)));
        var root = Path.GetFullPath(paths.Root).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar) + Path.DirectorySeparatorChar;
        if (!full.StartsWith(root, StringComparison.OrdinalIgnoreCase) || !File.Exists(full)) return Results.NotFound();
        return Results.File(full, ContentType(full), enableRangeProcessing: true);
    }

    private static string Index(AppPaths paths)
    {
        var file = Path.Combine(paths.Root, "index.php");
        var html = File.ReadAllText(file);
        html = Regex.Replace(html, @"<\?php.*?\?>", "", RegexOptions.Singleline);
        html = Regex.Replace(html, @"<\?= app_asset\('([^']+)'\) \?>", match =>
        {
            var rel = match.Groups[1].Value;
            var full = Path.Combine(paths.Root, rel.Replace('/', Path.DirectorySeparatorChar));
            var stamp = File.Exists(full) ? File.GetLastWriteTimeUtc(full).Ticks.ToString() : "1";
            return rel + "?v=" + stamp;
        });
        return html;
    }

    private static async Task<JsonObject> ReadBody(HttpRequest request)
    {
        using var reader = new StreamReader(request.Body);
        var raw = await reader.ReadToEndAsync();
        if (string.IsNullOrWhiteSpace(raw)) throw new InvalidDataException("Invalid project data.");
        return JsonNode.Parse(raw) as JsonObject ?? throw new InvalidDataException("Invalid project data.");
    }

    private static IResult Ok(string message, JsonObject extra)
    {
        extra["ok"] = true;
        extra["message"] = message;
        return Results.Json(extra);
    }

    private static IResult Err(string message, int status = 400)
    {
        return Results.Json(new { ok = false, message }, statusCode: status);
    }

    private static string Safe(string message)
    {
        var known = new[]
        {
            "Unsupported file type.", "File is too large.", "Upload failed.", "Missing media.",
            "Media could not be analysed.", "FFmpeg is unavailable.", "FFprobe is unavailable.",
            "Empty project.", "Invalid project data.", "Export failed.", "Export job not found.",
            "Project has too many elements.", "A required font file is unavailable on the server.",
            "Processing timed out.", "Media not found.", "Invalid media path."
        };
        return known.Contains(message) ? message : "The request could not be completed.";
    }

    private static string ContentType(string path) => Path.GetExtension(path).ToLowerInvariant() switch
    {
        ".html" => "text/html",
        ".css" => "text/css",
        ".js" => "text/javascript",
        ".json" => "application/json",
        ".png" => "image/png",
        ".jpg" or ".jpeg" => "image/jpeg",
        ".webp" => "image/webp",
        ".mp4" => "video/mp4",
        ".webm" => "video/webm",
        ".mov" => "video/quicktime",
        ".wmv" => "video/x-ms-wmv",
        ".mp3" => "audio/mpeg",
        ".wav" => "audio/wav",
        ".aac" => "audio/aac",
        ".m4a" => "audio/mp4",
        _ => "application/octet-stream",
    };

    private static int FreePort()
    {
        var listener = new TcpListener(IPAddress.Loopback, 0);
        listener.Start();
        var port = ((IPEndPoint)listener.LocalEndpoint).Port;
        listener.Stop();
        return port;
    }
}
