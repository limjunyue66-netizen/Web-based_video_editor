namespace VideoEditor;

internal static class Program
{
    [STAThread]
    private static void Main(string[] args)
    {
        var paths = AppPaths.Discover();
        paths.EnsureFolders();
        var server = EditorServer.Start(paths);
        File.WriteAllText(Path.Combine(paths.Logs, "desktop-url.txt"), server.Url);
        if (args.Any(arg => arg is "--serve" or "/serve"))
        {
            Console.WriteLine(server.Url);
            Thread.Sleep(Timeout.Infinite);
            return;
        }

        ApplicationConfiguration.Initialize();
        Application.Run(new MainForm(server));
        server.Stop();
    }
}
