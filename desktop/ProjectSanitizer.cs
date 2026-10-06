using System.Text.Json.Nodes;
using System.Text.RegularExpressions;

namespace VideoEditor;

internal static class ProjectSanitizer
{
    private static readonly string[] Fonts = ["Arial", "Verdana", "Georgia", "Times New Roman", "Courier New"];

    public static JsonObject Sanitize(JsonObject input, MediaLibrary library)
    {
        var name = Regex.Replace(JsonUtil.Str(input["name"], "Untitled Project").Trim(), @"[\u0000-\u001F\u007F]", "");
        if (name.Length == 0 || name.Length > 80) name = "Untitled Project";
        var fps = JsonUtil.Int(input["fps"], 30);
        if (fps is not (24 or 25 or 30 or 60)) fps = 30;
        var width = JsonUtil.Even(JsonUtil.ClampInt(JsonUtil.Int(input["width"], 1920), 320, 1920), 320);
        var height = JsonUtil.Even(JsonUtil.ClampInt(JsonUtil.Int(input["height"], 1080), 320, 1920), 320);
        var filtersIn = JsonUtil.Obj(input["filters"]);
        var filters = new JsonObject
        {
            ["brightness"] = JsonUtil.Clamp(JsonUtil.Num(filtersIn["brightness"]), -100, 100),
            ["contrast"] = JsonUtil.Clamp(JsonUtil.Num(filtersIn["contrast"]), -100, 100),
            ["saturation"] = JsonUtil.Clamp(JsonUtil.Num(filtersIn["saturation"]), -100, 100),
            ["grayscale"] = JsonUtil.Clamp(JsonUtil.Num(filtersIn["grayscale"]), 0, 100),
            ["blur"] = JsonUtil.Clamp(JsonUtil.Num(filtersIn["blur"]), 0, 20),
            ["sepia"] = JsonUtil.Clamp(JsonUtil.Num(filtersIn["sepia"]), 0, 100),
        };
        var clips = Clips(input["clips"], library);
        var images = Images(input["images"], library);
        var texts = Texts(input["texts"]);
        var audios = Audios(input["audios"], library);
        if (clips.Count + images.Count + texts.Count + audios.Count > 80) throw new InvalidDataException("Project has too many elements.");
        return new JsonObject
        {
            ["name"] = name,
            ["fps"] = fps,
            ["width"] = width,
            ["height"] = height,
            ["clips"] = clips,
            ["images"] = images,
            ["texts"] = texts,
            ["audios"] = audios,
            ["filters"] = filters,
            ["muteOriginal"] = JsonUtil.Bool(input["muteOriginal"]),
            ["musicVolume"] = JsonUtil.Clamp(JsonUtil.Num(input["musicVolume"], 100), 0, 100),
            ["exportMode"] = JsonUtil.Str(input["exportMode"]) == "high" ? "high" : "fast",
        };
    }

    private static JsonArray Clips(JsonNode? node, MediaLibrary library)
    {
        var list = new JsonArray();
        foreach (var clip in JsonUtil.List(node))
        {
            var mediaId = JsonUtil.Str(clip["mediaId"]);
            var media = library.Load(mediaId) ?? throw new InvalidDataException("Missing media.");
            if (JsonUtil.Str(media["type"]) != "video") throw new InvalidDataException("Missing media.");
            var source = JsonUtil.Num(media["duration"]);
            if (source < 0.1) throw new InvalidDataException("Media could not be analysed.");
            var speed = JsonUtil.Clamp(JsonUtil.Num(clip["speed"], 1), 0.25, 4);
            var minSource = 0.1 * speed;
            var start = JsonUtil.Clamp(JsonUtil.Num(clip["inPoint"]), 0, Math.Max(0, source - minSource));
            var end = JsonUtil.Clamp(JsonUtil.Num(clip["outPoint"], source), start + minSource, source);
            var rotation = JsonUtil.Int(clip["rotation"]);
            if (rotation is not (0 or 90 or 180 or 270)) rotation = 0;
            var crop = JsonUtil.Obj(clip["crop"]);
            var width = Math.Max(2, JsonUtil.Int(media["width"], 2));
            var height = Math.Max(2, JsonUtil.Int(media["height"], 2));
            var left = JsonUtil.ClampInt(JsonUtil.Int(crop["left"]), 0, width - 2);
            var top = JsonUtil.ClampInt(JsonUtil.Int(crop["top"]), 0, height - 2);
            var right = JsonUtil.ClampInt(JsonUtil.Int(crop["right"]), 0, width - left - 2);
            var bottom = JsonUtil.ClampInt(JsonUtil.Int(crop["bottom"]), 0, height - top - 2);
            list.Add(new JsonObject
            {
                ["id"] = ElementId(clip["id"]),
                ["mediaId"] = mediaId,
                ["name"] = JsonUtil.DisplayName(JsonUtil.Str(clip["name"], JsonUtil.Str(media["name"], "Clip"))),
                ["timelineStart"] = Math.Max(0, JsonUtil.Num(clip["timelineStart"])),
                ["inPoint"] = start,
                ["outPoint"] = end,
                ["sourceDuration"] = source,
                ["speed"] = speed,
                ["volume"] = JsonUtil.Clamp(JsonUtil.Num(clip["volume"], 100), 0, 100),
                ["muted"] = JsonUtil.Bool(clip["muted"]),
                ["x"] = JsonUtil.Clamp(JsonUtil.Num(clip["x"]), -4000, 4000),
                ["y"] = JsonUtil.Clamp(JsonUtil.Num(clip["y"]), -4000, 4000),
                ["scale"] = JsonUtil.Clamp(JsonUtil.Num(clip["scale"], 1), 0.05, 4),
                ["rotation"] = rotation,
                ["crop"] = new JsonObject { ["left"] = left, ["top"] = top, ["right"] = right, ["bottom"] = bottom },
                ["media"] = media.DeepClone(),
            });
        }
        return list;
    }

    private static JsonArray Images(JsonNode? node, MediaLibrary library)
    {
        var list = new JsonArray();
        foreach (var image in JsonUtil.List(node))
        {
            var mediaId = JsonUtil.Str(image["mediaId"]);
            var media = library.Load(mediaId) ?? throw new InvalidDataException("Missing media.");
            if (JsonUtil.Str(media["type"]) != "image") throw new InvalidDataException("Missing media.");
            var start = Math.Max(0, JsonUtil.Num(image["start"]));
            var end = JsonUtil.Num(image["end"], start + 5);
            if (end < start + 0.1) end = start + 0.1;
            var rotation = JsonUtil.Int(image["rotation"]);
            if (rotation is not (0 or 90 or 180 or 270)) rotation = 0;
            list.Add(new JsonObject
            {
                ["id"] = ElementId(image["id"]),
                ["mediaId"] = mediaId,
                ["name"] = JsonUtil.DisplayName(JsonUtil.Str(image["name"], JsonUtil.Str(media["name"], "Image"))),
                ["x"] = JsonUtil.Clamp(JsonUtil.Num(image["x"], 40), -4000, 4000),
                ["y"] = JsonUtil.Clamp(JsonUtil.Num(image["y"], 40), -4000, 4000),
                ["width"] = JsonUtil.Clamp(JsonUtil.Num(image["width"], 480), 8, 4000),
                ["scale"] = JsonUtil.Clamp(JsonUtil.Num(image["scale"], 1), 0.05, 5),
                ["rotation"] = rotation,
                ["opacity"] = JsonUtil.Clamp(JsonUtil.Num(image["opacity"], 100), 0, 100),
                ["start"] = start,
                ["end"] = end,
                ["media"] = media.DeepClone(),
            });
        }
        return list;
    }

    private static JsonArray Texts(JsonNode? node)
    {
        var list = new JsonArray();
        foreach (var text in JsonUtil.List(node))
        {
            var content = JsonUtil.Str(text["content"]).Replace("\r\n", "\n").Replace('\r', '\n').Trim();
            content = Regex.Replace(content, @"[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]", "");
            if (content.Length == 0) continue;
            if (content.Length > 240) content = content[..240];
            var start = Math.Max(0, JsonUtil.Num(text["start"]));
            var end = JsonUtil.Num(text["end"], start + 5);
            if (end < start + 0.1) end = start + 0.1;
            var family = JsonUtil.Str(text["fontFamily"], "Arial");
            if (!Fonts.Contains(family)) family = "Arial";
            var align = JsonUtil.Str(text["align"], "center");
            if (align is not ("left" or "center" or "right")) align = "center";
            var rotation = JsonUtil.Int(text["rotation"]);
            if (rotation is not (0 or 90 or 180 or 270)) rotation = 0;
            var color = JsonUtil.Str(text["color"], "#ffffff").ToLowerInvariant();
            if (!Regex.IsMatch(color, "^#[0-9a-f]{6}$")) color = "#ffffff";
            list.Add(new JsonObject
            {
                ["id"] = ElementId(text["id"]),
                ["content"] = content,
                ["fontFamily"] = family,
                ["fontSize"] = JsonUtil.ClampInt(JsonUtil.Int(text["fontSize"], 64), 8, 320),
                ["color"] = color,
                ["bold"] = JsonUtil.Bool(text["bold"]),
                ["italic"] = JsonUtil.Bool(text["italic"]),
                ["align"] = align,
                ["x"] = JsonUtil.Clamp(JsonUtil.Num(text["x"], 960), -4000, 4000),
                ["y"] = JsonUtil.Clamp(JsonUtil.Num(text["y"], 540), -4000, 4000),
                ["start"] = start,
                ["end"] = end,
                ["opacity"] = JsonUtil.Clamp(JsonUtil.Num(text["opacity"], 100), 0, 100),
                ["rotation"] = rotation,
                ["boxWidth"] = JsonUtil.Clamp(JsonUtil.Num(text["boxWidth"]), 0, 4000),
                ["boxHeight"] = JsonUtil.Clamp(JsonUtil.Num(text["boxHeight"]), 0, 2000),
            });
        }
        return list;
    }

    private static JsonArray Audios(JsonNode? node, MediaLibrary library)
    {
        var list = new JsonArray();
        foreach (var audio in JsonUtil.List(node))
        {
            var mediaId = JsonUtil.Str(audio["mediaId"]);
            var media = library.Load(mediaId) ?? throw new InvalidDataException("Missing media.");
            if (JsonUtil.Str(media["type"]) != "audio") throw new InvalidDataException("Missing media.");
            var source = JsonUtil.Num(media["duration"]);
            if (source < 0.1) throw new InvalidDataException("Media could not be analysed.");
            var start = JsonUtil.Clamp(JsonUtil.Num(audio["inPoint"]), 0, Math.Max(0, source - 0.1));
            var end = JsonUtil.Clamp(JsonUtil.Num(audio["outPoint"], source), start + 0.1, source);
            list.Add(new JsonObject
            {
                ["id"] = ElementId(audio["id"]),
                ["mediaId"] = mediaId,
                ["name"] = JsonUtil.DisplayName(JsonUtil.Str(audio["name"], JsonUtil.Str(media["name"], "Audio"))),
                ["timelineStart"] = Math.Max(0, JsonUtil.Num(audio["timelineStart"])),
                ["inPoint"] = start,
                ["outPoint"] = end,
                ["sourceDuration"] = source,
                ["volume"] = JsonUtil.Clamp(JsonUtil.Num(audio["volume"], 100), 0, 100),
                ["track"] = JsonUtil.ClampInt(JsonUtil.Int(audio["track"], 1), 1, 8),
                ["media"] = media.DeepClone(),
            });
        }
        return list;
    }

    private static string ElementId(JsonNode? node)
    {
        var id = JsonUtil.Str(node);
        return Regex.IsMatch(id, "^[a-f0-9]{8,32}$") ? id : JsonUtil.Id();
    }

    public static double Duration(JsonObject project)
    {
        var end = 0.0;
        foreach (var clip in JsonUtil.List(project["clips"]))
        {
            end = Math.Max(end, JsonUtil.Num(clip["timelineStart"]) + ((JsonUtil.Num(clip["outPoint"]) - JsonUtil.Num(clip["inPoint"])) / Math.Max(0.25, JsonUtil.Num(clip["speed"], 1))));
        }
        foreach (var image in JsonUtil.List(project["images"])) end = Math.Max(end, JsonUtil.Num(image["end"]));
        foreach (var text in JsonUtil.List(project["texts"])) end = Math.Max(end, JsonUtil.Num(text["end"]));
        foreach (var audio in JsonUtil.List(project["audios"]))
        {
            end = Math.Max(end, JsonUtil.Num(audio["timelineStart"]) + (JsonUtil.Num(audio["outPoint"]) - JsonUtil.Num(audio["inPoint"])));
        }
        if (end < 0.1) throw new InvalidDataException("Empty project.");
        return Math.Max(0.2, Math.Ceiling(end * 1000) / 1000);
    }
}
