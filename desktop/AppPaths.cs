namespace VideoEditor;

internal sealed class AppPaths
{
    public required string Root { get; init; }
    public required string Upload { get; init; }
    public required string Export { get; init; }
    public required string Logs { get; init; }

    public string Ffmpeg => Tool("ffmpeg");
    public string Ffprobe => Tool("ffprobe");

    public static AppPaths Discover()
    {
        var root = FindContentRoot();
        if (IsSingleFile)
        {
            var bundled = AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar);
            if (File.Exists(Path.Combine(bundled, "index.php")) && Directory.Exists(Path.Combine(bundled, "assets")))
            {
                root = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "VideoEditor");
                SyncFile(Path.Combine(bundled, "index.php"), Path.Combine(root, "index.php"));
                SyncTree(Path.Combine(bundled, "assets"), Path.Combine(root, "assets"));
                SyncFile(Path.Combine(bundled, "bin", "ffmpeg.exe"), Path.Combine(root, "bin", "ffmpeg.exe"));
                SyncFile(Path.Combine(bundled, "bin", "ffprobe.exe"), Path.Combine(root, "bin", "ffprobe.exe"));
            }
        }
        return new AppPaths
        {
            Root = root,
            Upload = Path.Combine(root, "uploads"),
            Export = Path.Combine(root, "exports"),
            Logs = Path.Combine(root, "logs"),
        };
    }

    private static bool IsSingleFile => string.IsNullOrEmpty(typeof(AppPaths).Assembly.Location);

    private static void SyncTree(string source, string dest)
    {
        if (!Directory.Exists(source)) return;
        foreach (var file in Directory.GetFiles(source, "*", SearchOption.AllDirectories))
        {
            var relative = Path.GetRelativePath(source, file);
            SyncFile(file, Path.Combine(dest, relative));
        }
    }

    private static void SyncFile(string source, string dest)
    {
        if (!File.Exists(source)) return;
        Directory.CreateDirectory(Path.GetDirectoryName(dest)!);
        var incoming = new FileInfo(source);
        var current = new FileInfo(dest);
        if (current.Exists && current.Length == incoming.Length) return;
        File.Copy(source, dest, true);
    }

    public void EnsureFolders()
    {
        foreach (var folder in new[]
        {
            Upload, Export, Logs,
            Path.Combine(Upload, "videos"),
            Path.Combine(Upload, "images"),
            Path.Combine(Upload, "audio"),
            Path.Combine(Upload, "thumbs"),
            Path.Combine(Upload, "previews"),
            Path.Combine(Upload, "meta"),
            Path.Combine(Export, "jobs"),
        })
        {
            Directory.CreateDirectory(folder);
        }
    }

    private string Tool(string name)
    {
        var local = Path.Combine(Root, "bin", name + ".exe");
        if (File.Exists(local)) return local;
        var beside = Path.Combine(AppContext.BaseDirectory, "bin", name + ".exe");
        if (File.Exists(beside)) return beside;
        if (!string.IsNullOrEmpty(Environment.ProcessPath))
        {
            var nextToExe = Path.Combine(Path.GetDirectoryName(Environment.ProcessPath)!, "bin", name + ".exe");
            if (File.Exists(nextToExe)) return nextToExe;
        }
        var pathEnv = Environment.GetEnvironmentVariable("PATH") ?? "";
        foreach (var dir in pathEnv.Split(Path.PathSeparator, StringSplitOptions.RemoveEmptyEntries))
        {
            var candidate = Path.Combine(dir.Trim(), name + ".exe");
            if (File.Exists(candidate)) return candidate;
        }
        return local;
    }

    private static string FindContentRoot()
    {
        var dir = AppContext.BaseDirectory;
        for (var i = 0; i < 8; i++)
        {
            if (File.Exists(Path.Combine(dir, "index.php")) && Directory.Exists(Path.Combine(dir, "assets")))
            {
                return dir;
            }
            var parent = Directory.GetParent(dir);
            if (parent == null) break;
            dir = parent.FullName;
        }
        return AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar);
    }
}
