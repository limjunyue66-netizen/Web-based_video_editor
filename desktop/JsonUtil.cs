using System.Text.Json;
using System.Text.Json.Nodes;

namespace VideoEditor;

internal static class JsonUtil
{
    public static readonly JsonSerializerOptions Write = new() { WriteIndented = false };

    public static double Num(JsonNode? node, double fallback = 0)
    {
        if (node is null) return fallback;
        if (node is JsonValue value)
        {
            if (value.TryGetValue<double>(out var number)) return number;
            if (value.TryGetValue<int>(out var integer)) return integer;
            if (value.TryGetValue<long>(out var longer)) return longer;
            if (value.TryGetValue<JsonElement>(out var element) && element.ValueKind == JsonValueKind.Number && element.TryGetDouble(out var fromElement)) return fromElement;
            if (value.TryGetValue<string>(out var text) && double.TryParse(text, System.Globalization.NumberStyles.Float, System.Globalization.CultureInfo.InvariantCulture, out var parsed))
            {
                return parsed;
            }
        }
        return fallback;
    }

    public static int Int(JsonNode? node, int fallback = 0) => (int)Math.Round(Num(node, fallback));

    public static bool Bool(JsonNode? node) => node is JsonValue value && value.TryGetValue<bool>(out var flag) && flag;

    public static string Str(JsonNode? node, string fallback = "")
    {
        if (node is JsonValue value && value.TryGetValue<string>(out var text)) return text;
        return fallback;
    }

    public static JsonObject Obj(JsonNode? node) => node as JsonObject ?? new JsonObject();

    public static IEnumerable<JsonObject> List(JsonNode? node)
    {
        if (node is not JsonArray array) yield break;
        foreach (var item in array)
        {
            if (item is JsonObject obj) yield return obj;
        }
    }

    public static double Clamp(double value, double min, double max) => Math.Max(min, Math.Min(max, value));

    public static int ClampInt(int value, int min, int max) => Math.Max(min, Math.Min(max, value));

    public static int Even(int value, int min = 2)
    {
        if (value < min) value = min;
        if (value % 2 != 0) value--;
        if (value < min) value = min % 2 == 0 ? min : min + 1;
        return value;
    }

    public static string Ff(double value)
    {
        if (double.IsNaN(value) || double.IsInfinity(value)) return "0";
        var text = value.ToString("0.####", System.Globalization.CultureInfo.InvariantCulture);
        return text == "-0" ? "0" : text;
    }

    public static string Id() => Convert.ToHexString(Guid.NewGuid().ToByteArray()[..8]).ToLowerInvariant();

    public static bool SafeId(string id) => id.Length == 16 && id.All(Uri.IsHexDigit);

    public static string DisplayName(string name)
    {
        name = Path.GetFileName(name.Replace('\\', '/'));
        var cleaned = new string(name.Where(ch => ch >= ' ' && ch != '\u007f').ToArray()).Trim();
        if (cleaned.Length > 140) cleaned = cleaned[..140];
        return cleaned.Length == 0 ? "file" : cleaned;
    }
}
