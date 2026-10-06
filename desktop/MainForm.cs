using Microsoft.Web.WebView2.WinForms;

namespace VideoEditor;

internal sealed class MainForm : Form
{
    private readonly EditorServer _server;

    public MainForm(EditorServer server)
    {
        _server = server;
        Text = "Video Editor";
        StartPosition = FormStartPosition.CenterScreen;
        WindowState = FormWindowState.Maximized;
        MinimumSize = new Size(1100, 700);
        BackColor = Color.FromArgb(11, 13, 18);
        Shown += OnShown;
    }

    private async void OnShown(object? sender, EventArgs e)
    {
        var web = new WebView2 { Dock = DockStyle.Fill };
        Controls.Add(web);
        try
        {
            var folder = Path.Combine(Path.GetTempPath(), "VideoEditor", "webview");
            Directory.CreateDirectory(folder);
            var environment = await Microsoft.Web.WebView2.Core.CoreWebView2Environment.CreateAsync(null, folder);
            await web.EnsureCoreWebView2Async(environment);
            web.CoreWebView2.Settings.AreDevToolsEnabled = false;
            web.Source = new Uri(_server.Url);
        }
        catch (Exception ex)
        {
            Controls.Remove(web);
            var message = new Label
            {
                Dock = DockStyle.Fill,
                ForeColor = Color.White,
                TextAlign = ContentAlignment.MiddleCenter,
                Font = new Font("Segoe UI", 12),
                Text = "The desktop window needs the Microsoft Edge WebView2 runtime.\r\n\r\n" + ex.Message
                    + "\r\n\r\nInstall it from https://developer.microsoft.com/microsoft-edge/webview2/ and open Video Editor again.",
            };
            Controls.Add(message);
        }
    }
}
