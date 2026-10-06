using System.Text;
using System.Text.Json.Nodes;

namespace VideoEditor;

internal sealed record ExportPlan(List<string> Args, string Filter, double Duration, int ExportWidth, int ExportHeight);

internal static class FilterGraph
{
    public static ExportPlan Build(JsonObject project, string jobDir, MediaLibrary library)
    {
        var duration = ProjectSanitizer.Duration(project);
        var fps = JsonUtil.Int(project["fps"], 30);
        var canvasW = JsonUtil.Int(project["width"], 1920);
        var canvasH = JsonUtil.Int(project["height"], 1080);
        var high = JsonUtil.Str(project["exportMode"]) == "high";
        var exportW = high ? 1920 : 1280;
        var exportH = high ? 1080 : 720;
        var frame = CompositionFrame(project, canvasW, canvasH, fps);
        var args = new List<string>();
        var chains = new List<string>();
        var audioLabels = new List<string>();
        var video = "base0";
        chains.Add($"color=c=black:s={frame.W}x{frame.H}:r={fps}:d={JsonUtil.Ff(duration)},format=yuv420p,setsar=1[{video}]");

        var clipIndex = 0;
        foreach (var clip in JsonUtil.List(project["clips"]))
        {
            var media = JsonUtil.Obj(clip["media"]);
            var input = AddInput(args, ["-i", library.Absolute(media)]);
            var built = VideoClip(clip, canvasW, canvasH, fps);
            var clipLabel = "clip" + clipIndex;
            chains.Add($"[{input}:v]{string.Join(",", built.Filters)}[{clipLabel}]");
            var next = "v" + clipIndex;
            chains.Add($"[{video}][{clipLabel}]overlay=x={built.X - frame.X}:y={built.Y - frame.Y}:eof_action=pass:repeatlast=0[{next}]");
            video = next;
            var useAudio = !JsonUtil.Bool(project["muteOriginal"]) && !JsonUtil.Bool(clip["muted"]) && JsonUtil.Num(clip["volume"]) > 0 && JsonUtil.Bool(media["hasAudio"]);
            if (useAudio)
            {
                audioLabels.Add(AudioChain(chains, input, JsonUtil.Num(clip["inPoint"]), JsonUtil.Num(clip["outPoint"]), JsonUtil.Num(clip["speed"], 1), JsonUtil.Num(clip["volume"]) / 100, JsonUtil.Num(clip["timelineStart"]), "va" + clipIndex));
            }
            clipIndex++;
        }

        video = ColorFilters(chains, video, JsonUtil.Obj(project["filters"]));

        var imageIndex = 0;
        foreach (var image in JsonUtil.List(project["images"]))
        {
            var media = JsonUtil.Obj(image["media"]);
            var input = AddInput(args, ["-loop", "1", "-framerate", fps.ToString(), "-t", JsonUtil.Ff(duration), "-i", library.Absolute(media)]);
            var layout = ImageLayout(image);
            var label = "img" + imageIndex;
            var filters = new List<string>
            {
                $"scale={layout.Width}:{layout.Height}:flags=lanczos",
                "format=rgba",
                "colorchannelmixer=aa=" + JsonUtil.Ff(JsonUtil.Num(image["opacity"], 100) / 100),
            };
            filters.AddRange(Rotation(JsonUtil.Int(image["rotation"])));
            chains.Add($"[{input}:v]{string.Join(",", filters)}[{label}]");
            var next = "i" + imageIndex;
            chains.Add($"[{video}][{label}]overlay=x={layout.X - frame.X}:y={layout.Y - frame.Y}:enable={Between(JsonUtil.Num(image["start"]), JsonUtil.Num(image["end"]))}:eof_action=pass:repeatlast=0[{next}]");
            video = next;
            imageIndex++;
        }

        var textIndex = 0;
        foreach (var text in JsonUtil.List(project["texts"]))
        {
            video = Text(chains, args, text, video, textIndex, duration, fps, jobDir, frame.X, frame.Y);
            textIndex++;
        }

        var audioIndex = 0;
        foreach (var audio in JsonUtil.List(project["audios"]))
        {
            var gain = (JsonUtil.Num(audio["volume"], 100) / 100) * (JsonUtil.Num(project["musicVolume"], 100) / 100);
            if (gain <= 0) continue;
            var input = AddInput(args, ["-i", library.Absolute(JsonUtil.Obj(audio["media"]))]);
            audioLabels.Add(AudioChain(chains, input, JsonUtil.Num(audio["inPoint"]), JsonUtil.Num(audio["outPoint"]), 1, gain, JsonUtil.Num(audio["timelineStart"]), "bg" + audioIndex));
            audioIndex++;
        }

        chains.Add($"[{video}]scale={exportW}:{exportH}:force_original_aspect_ratio=decrease:out_range=tv,pad={exportW}:{exportH}:(ow-iw)/2:(oh-ih)/2:black,setsar=1,fps={fps},format=yuv420p[vout]");
        audioLabels = audioLabels.Where(label => label.Length > 0).ToList();
        if (audioLabels.Count == 0)
        {
            var input = AddInput(args, ["-f", "lavfi", "-t", JsonUtil.Ff(duration), "-i", "anullsrc=channel_layout=stereo:sample_rate=48000"]);
            chains.Add($"[{input}:a]aformat=sample_fmts=fltp:channel_layouts=stereo,atrim=end={JsonUtil.Ff(duration)}[aout]");
        }
        else if (audioLabels.Count == 1)
        {
            chains.Add($"[{audioLabels[0]}]apad=whole_dur={JsonUtil.Ff(duration)},atrim=end={JsonUtil.Ff(duration)},aresample=48000,aformat=sample_fmts=fltp:channel_layouts=stereo[aout]");
        }
        else
        {
            var mix = string.Concat(audioLabels.Select(label => "[" + label + "]"));
            chains.Add($"{mix}amix=inputs={audioLabels.Count}:duration=longest:dropout_transition=0:normalize=0,apad=whole_dur={JsonUtil.Ff(duration)},atrim=end={JsonUtil.Ff(duration)},aresample=48000,aformat=sample_fmts=fltp:channel_layouts=stereo[aout]");
        }

        return new ExportPlan(args, string.Join(";\n", chains), duration, exportW, exportH);
    }

    private static (int X, int Y, int W, int H) CompositionFrame(JsonObject project, int canvasW, int canvasH, int fps)
    {
        double minX = 0, minY = 0, maxX = canvasW, maxY = canvasH;
        foreach (var clip in JsonUtil.List(project["clips"]))
        {
            var built = VideoClip(clip, canvasW, canvasH, fps);
            minX = Math.Min(minX, built.X); minY = Math.Min(minY, built.Y);
            maxX = Math.Max(maxX, built.X + built.W); maxY = Math.Max(maxY, built.Y + built.H);
        }
        foreach (var image in JsonUtil.List(project["images"]))
        {
            var layout = ImageLayout(image);
            minX = Math.Min(minX, layout.X); minY = Math.Min(minY, layout.Y);
            maxX = Math.Max(maxX, layout.X + layout.BoxW); maxY = Math.Max(maxY, layout.Y + layout.BoxH);
        }
        foreach (var text in JsonUtil.List(project["texts"]))
        {
            var rect = TextBounds(text);
            minX = Math.Min(minX, rect.X); minY = Math.Min(minY, rect.Y);
            maxX = Math.Max(maxX, rect.X + rect.W); maxY = Math.Max(maxY, rect.Y + rect.H);
        }
        var originX = (int)Math.Floor(minX);
        var originY = (int)Math.Floor(minY);
        var width = (int)Math.Ceiling(maxX) - originX;
        var height = (int)Math.Ceiling(maxY) - originY;
        if (width % 2 != 0) width++;
        if (height % 2 != 0) height++;
        return (originX, originY, Math.Max(2, width), Math.Max(2, height));
    }

    private static (List<string> Filters, int X, int Y, int W, int H) VideoClip(JsonObject clip, int canvasW, int canvasH, int fps)
    {
        var media = JsonUtil.Obj(clip["media"]);
        var srcW = Math.Max(2, JsonUtil.Int(media["width"], 2));
        var srcH = Math.Max(2, JsonUtil.Int(media["height"], 2));
        var crop = JsonUtil.Obj(clip["crop"]);
        var cropW = JsonUtil.Even(srcW - JsonUtil.Int(crop["left"]) - JsonUtil.Int(crop["right"]));
        var cropH = JsonUtil.Even(srcH - JsonUtil.Int(crop["top"]) - JsonUtil.Int(crop["bottom"]));
        var fitW = cropW;
        var fitH = cropH;
        var rotation = JsonUtil.Int(clip["rotation"]);
        if (rotation is 90 or 270) { fitW = cropH; fitH = cropW; }
        var fit = Math.Min(canvasW / (double)fitW, canvasH / (double)fitH) * JsonUtil.Num(clip["scale"], 1);
        var dispW = JsonUtil.Even((int)Math.Round(fitW * fit));
        var dispH = JsonUtil.Even((int)Math.Round(fitH * fit));
        var x = (int)Math.Round((canvasW - dispW) / 2.0 + JsonUtil.Num(clip["x"]));
        var y = (int)Math.Round((canvasH - dispH) / 2.0 + JsonUtil.Num(clip["y"]));
        var filters = new List<string>
        {
            "trim=start=" + JsonUtil.Ff(JsonUtil.Num(clip["inPoint"])) + ":end=" + JsonUtil.Ff(JsonUtil.Num(clip["outPoint"])),
            "setpts=PTS-STARTPTS",
        };
        if (Math.Abs(JsonUtil.Num(clip["speed"], 1) - 1) > 0.001) filters.Add("setpts=PTS/" + JsonUtil.Ff(JsonUtil.Num(clip["speed"], 1)));
        filters.Add("fps=" + fps);
        if (JsonUtil.Int(crop["left"]) != 0 || JsonUtil.Int(crop["top"]) != 0 || JsonUtil.Int(crop["right"]) != 0 || JsonUtil.Int(crop["bottom"]) != 0)
        {
            filters.Add($"crop={cropW}:{cropH}:{JsonUtil.Int(crop["left"])}:{JsonUtil.Int(crop["top"])}");
        }
        filters.AddRange(Rotation(rotation));
        filters.Add($"scale={dispW}:{dispH}:flags=lanczos");
        filters.Add("setsar=1");
        filters.Add("format=yuv420p");
        filters.Add("setpts=PTS+" + JsonUtil.Ff(JsonUtil.Num(clip["timelineStart"])) + "/TB");
        return (filters, x, y, dispW, dispH);
    }

    private static (int Width, int Height, int X, int Y, int BoxW, int BoxH) ImageLayout(JsonObject image)
    {
        var media = JsonUtil.Obj(image["media"]);
        var srcW = Math.Max(1, JsonUtil.Int(media["width"], 1));
        var srcH = Math.Max(1, JsonUtil.Int(media["height"], 1));
        var dispW = JsonUtil.Even((int)Math.Round(JsonUtil.Num(image["width"], 480) * JsonUtil.Num(image["scale"], 1)));
        var dispH = JsonUtil.Even((int)Math.Round(dispW * (srcH / (double)srcW)));
        var boxW = dispW;
        var boxH = dispH;
        if (JsonUtil.Int(image["rotation"]) is 90 or 270) { boxW = dispH; boxH = dispW; }
        var centerX = JsonUtil.Num(image["x"]) + dispW / 2.0;
        var centerY = JsonUtil.Num(image["y"]) + dispH / 2.0;
        return (dispW, dispH, (int)Math.Round(centerX - boxW / 2.0), (int)Math.Round(centerY - boxH / 2.0), boxW, boxH);
    }

    private static string ColorFilters(List<string> chains, string label, JsonObject filters)
    {
        var brightness = JsonUtil.Ff(JsonUtil.Num(filters["brightness"]) / 100);
        var contrast = JsonUtil.Ff(JsonUtil.Clamp(1 + JsonUtil.Num(filters["contrast"]) / 100, 0, 2));
        var saturation = JsonUtil.Ff(JsonUtil.Clamp((1 + JsonUtil.Num(filters["saturation"]) / 100) * (1 - JsonUtil.Num(filters["grayscale"]) / 100), 0, 3));
        var next = label + "eq";
        chains.Add($"[{label}]eq=brightness={brightness}:contrast={contrast}:saturation={saturation}[{next}]");
        label = next;
        if (JsonUtil.Num(filters["blur"]) > 0)
        {
            next = label + "bl";
            chains.Add($"[{label}]gblur=sigma={JsonUtil.Ff(JsonUtil.Num(filters["blur"]))}[{next}]");
            label = next;
        }
        if (JsonUtil.Num(filters["sepia"]) > 0)
        {
            var amount = JsonUtil.Ff(JsonUtil.Num(filters["sepia"]) / 100);
            chains.Add($"[{label}]split[{label}A][{label}B]");
            chains.Add($"[{label}B]colorchannelmixer=.393:.769:.189:0:.349:.686:.168:0:.272:.534:.131[{label}S]");
            var output = label + "sp";
            chains.Add($"[{label}A][{label}S]blend=all_expr='A*(1-{amount})+B*{amount}'[{output}]");
            label = output;
        }
        return label;
    }

    private static string Text(List<string> chains, List<string> args, JsonObject text, string video, int index, double duration, int fps, string jobDir, int originX, int originY)
    {
        var path = Path.Combine(jobDir, "text_" + index + ".txt");
        File.WriteAllText(path, JsonUtil.Str(text["content"]).Replace('\n', ' '));
        var font = Escape(FontFile(JsonUtil.Str(text["fontFamily"], "Arial"), JsonUtil.Bool(text["bold"]), JsonUtil.Bool(text["italic"])));
        var opacity = JsonUtil.Ff(JsonUtil.Num(text["opacity"], 100) / 100);
        var fontColor = "0x" + JsonUtil.Str(text["color"], "#ffffff").TrimStart('#').ToUpperInvariant() + "@" + opacity;
        var file = Escape(path);
        var enable = Between(JsonUtil.Num(text["start"]), JsonUtil.Num(text["end"]));
        var size = JsonUtil.Int(text["fontSize"], 64);
        if (JsonUtil.Int(text["rotation"]) == 0)
        {
            var x = TextX(text, originX);
            var y = (JsonUtil.Int(text["y"]) - originY) + "-text_h/2";
            var next = "tx" + index;
            chains.Add($"[{video}]drawtext=expansion=none:fontfile='{font}':textfile='{file}':fontsize={size}:fontcolor={fontColor}:x={x}:y={y}:enable={enable}[{next}]");
            return next;
        }
        var box = TextBox(text);
        var background = box.Dark ? "white" : "black";
        var key = box.Dark ? "0xFFFFFF" : "0x000000";
        var input = AddInput(args, ["-f", "lavfi", "-t", JsonUtil.Ff(duration), "-i", $"color=c={background}:s={box.BoxW}x{box.BoxH}:r={fps}"]);
        var label = "txt" + index;
        var parts = new List<string>
        {
            $"drawtext=expansion=none:fontfile='{font}':textfile='{file}':fontsize={size}:fontcolor={fontColor}:x=(w-text_w)/2:y=(h-text_h)/2",
            "format=rgba",
            "colorkey=" + key + ":0.12:0.06",
        };
        parts.AddRange(Rotation(JsonUtil.Int(text["rotation"])));
        chains.Add($"[{input}:v]{string.Join(",", parts)}[{label}]");
        var rotation = JsonUtil.Int(text["rotation"]);
        var bbW = rotation is 90 or 270 ? box.BoxH : box.BoxW;
        var bbH = rotation is 90 or 270 ? box.BoxW : box.BoxH;
        var center = TextCenter(text, box);
        var overlayX = (int)Math.Round(center.X - bbW / 2.0) - originX;
        var overlayY = (int)Math.Round(center.Y - bbH / 2.0) - originY;
        var done = "ty" + index;
        chains.Add($"[{video}][{label}]overlay=x={overlayX}:y={overlayY}:enable={enable}:eof_action=pass:repeatlast=0[{done}]");
        return done;
    }

    private static string TextX(JsonObject text, int originX)
    {
        var x = JsonUtil.Int(text["x"]) - originX;
        return JsonUtil.Str(text["align"], "center") switch
        {
            "left" => x.ToString(),
            "right" => x + "-text_w",
            _ => x + "-text_w/2",
        };
    }

    private static (double X, double Y, double W, double H) TextBounds(JsonObject text)
    {
        var box = TextBox(text);
        var rotation = JsonUtil.Int(text["rotation"]);
        if (rotation == 0)
        {
            var x = JsonUtil.Str(text["align"], "center") switch
            {
                "left" => JsonUtil.Num(text["x"]),
                "right" => JsonUtil.Num(text["x"]) - box.TextW,
                _ => JsonUtil.Num(text["x"]) - box.TextW / 2,
            };
            return (x, JsonUtil.Num(text["y"]) - box.TextH / 2, box.TextW, box.TextH);
        }
        var boxW = rotation is 90 or 270 ? box.BoxH : box.BoxW;
        var boxH = rotation is 90 or 270 ? box.BoxW : box.BoxH;
        var center = TextCenter(text, box);
        return (center.X - boxW / 2.0, center.Y - boxH / 2.0, boxW, boxH);
    }

    private static (double TextW, double TextH, int BoxW, int BoxH, bool Dark) TextBox(JsonObject text)
    {
        var size = JsonUtil.Int(text["fontSize"], 64);
        var content = JsonUtil.Str(text["content"]);
        var lines = content.Split('\n');
        var longest = Math.Max(1, lines.Max(line => line.Length));
        var estimatedW = JsonUtil.Num(text["boxWidth"]) > 8 ? JsonUtil.Num(text["boxWidth"]) : size * 0.62 * longest;
        var estimatedH = JsonUtil.Num(text["boxHeight"]) > 8 ? JsonUtil.Num(text["boxHeight"]) : size * 1.25 * lines.Length;
        var hex = JsonUtil.Str(text["color"], "#ffffff").TrimStart('#');
        var red = Convert.ToInt32(hex[..2], 16);
        var green = Convert.ToInt32(hex.Substring(2, 2), 16);
        var blue = Convert.ToInt32(hex.Substring(4, 2), 16);
        var luma = 0.2126 * red + 0.7152 * green + 0.0722 * blue;
        return (estimatedW, estimatedH, JsonUtil.Even((int)Math.Ceiling(estimatedW + size * 0.8)), JsonUtil.Even((int)Math.Ceiling(estimatedH + size * 0.8)), luma < 90);
    }

    private static (double X, double Y) TextCenter(JsonObject text, (double TextW, double TextH, int BoxW, int BoxH, bool Dark) box)
    {
        var x = JsonUtil.Num(text["x"]);
        var center = JsonUtil.Str(text["align"], "center") switch
        {
            "left" => x + box.TextW / 2,
            "right" => x - box.TextW / 2,
            _ => x,
        };
        return (center, JsonUtil.Num(text["y"]));
    }

    private static string AudioChain(List<string> chains, int input, double inPoint, double outPoint, double speed, double volume, double timelineStart, string label)
    {
        var parts = new List<string> { "atrim=start=" + JsonUtil.Ff(inPoint) + ":end=" + JsonUtil.Ff(outPoint), "asetpts=PTS-STARTPTS" };
        parts.AddRange(Atempo(speed));
        parts.Add("volume=" + JsonUtil.Ff(Math.Max(0, volume)));
        var delay = Math.Max(0, (int)Math.Round(timelineStart * 1000));
        if (delay > 0) parts.Add($"adelay={delay}|{delay}");
        parts.Add("aformat=sample_fmts=fltp:channel_layouts=stereo");
        chains.Add($"[{input}:a]{string.Join(",", parts)}[{label}]");
        return label;
    }

    private static IEnumerable<string> Atempo(double speed)
    {
        var filters = new List<string>();
        var remaining = speed;
        var guard = 0;
        while (remaining > 2.0001 && guard < 6) { filters.Add("atempo=2"); remaining /= 2; guard++; }
        while (remaining < 0.4999 && guard < 8) { filters.Add("atempo=0.5"); remaining /= 0.5; guard++; }
        if (Math.Abs(remaining - 1) > 0.001) filters.Add("atempo=" + JsonUtil.Ff(remaining));
        return filters;
    }

    private static IEnumerable<string> Rotation(int degrees) => degrees switch
    {
        90 => ["transpose=1"],
        180 => ["hflip", "vflip"],
        270 => ["transpose=2"],
        _ => [],
    };

    private static string Between(double start, double end) => "between(t\\," + JsonUtil.Ff(start) + "\\," + JsonUtil.Ff(end) + ")";

    private static string Escape(string path) => path.Replace('\\', '/').Replace(":", "\\:").Replace("'", "\\'");

    private static int AddInput(List<string> args, IEnumerable<string> tokens)
    {
        var index = args.Count(arg => arg == "-i");
        args.AddRange(tokens);
        return index;
    }

    private static string FontFile(string family, bool bold, bool italic)
    {
        var families = new Dictionary<string, string[]>
        {
            ["Arial"] = ["arial.ttf", "arialbd.ttf", "ariali.ttf", "arialbi.ttf"],
            ["Verdana"] = ["verdana.ttf", "verdanab.ttf", "verdanai.ttf", "verdanaz.ttf"],
            ["Georgia"] = ["georgia.ttf", "georgiab.ttf", "georgiai.ttf", "georgiaz.ttf"],
            ["Times New Roman"] = ["times.ttf", "timesbd.ttf", "timesi.ttf", "timesbi.ttf"],
            ["Courier New"] = ["cour.ttf", "courbd.ttf", "couri.ttf", "courbi.ttf"],
        };
        if (!families.ContainsKey(family)) family = "Arial";
        var index = (bold ? 1 : 0) + (italic ? 2 : 0);
        var windows = Environment.GetEnvironmentVariable("WINDIR") ?? @"C:\Windows";
        foreach (var file in new[] { families[family][index], families[family][0], "arial.ttf" })
        {
            var path = Path.Combine(windows, "Fonts", file);
            if (File.Exists(path)) return path;
        }
        throw new InvalidDataException("A required font file is unavailable on the server.");
    }
}
