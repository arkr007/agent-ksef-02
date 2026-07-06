param(
    [int] $Port = 8765
)

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
[System.Windows.Forms.Application]::EnableVisualStyles()

$listener = [System.Net.HttpListener]::new()
$prefixes = @(
    "http://127.0.0.1:$Port/",
    "http://localhost:$Port/"
)

foreach ($prefix in $prefixes) {
    $listener.Prefixes.Add($prefix)
}

function Set-CorsHeaders {
    param(
        [System.Net.HttpListenerResponse] $Response
    )

    $Response.Headers["Access-Control-Allow-Origin"] = "*"
    $Response.Headers["Access-Control-Allow-Methods"] = "GET, POST, OPTIONS"
    $Response.Headers["Access-Control-Allow-Headers"] = "Content-Type"
}

function Send-JsonResponse {
    param(
        [System.Net.HttpListenerContext] $Context,
        [int] $StatusCode,
        [hashtable] $Payload
    )

    $response = $Context.Response
    Set-CorsHeaders -Response $response
    $response.StatusCode = $StatusCode
    $response.ContentType = "application/json; charset=utf-8"

    $json = $Payload | ConvertTo-Json -Depth 6 -Compress
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)
    $response.ContentLength64 = $bytes.Length
    $response.OutputStream.Write($bytes, 0, $bytes.Length)
    $response.OutputStream.Close()
}

function Send-EmptyResponse {
    param(
        [System.Net.HttpListenerContext] $Context,
        [int] $StatusCode = 204
    )

    $response = $Context.Response
    Set-CorsHeaders -Response $response
    $response.StatusCode = $StatusCode
    $response.OutputStream.Close()
}

function Read-JsonBody {
    param(
        [System.Net.HttpListenerRequest] $Request
    )

    if (-not $Request.HasEntityBody) {
        return @{}
    }

    $reader = [System.IO.StreamReader]::new($Request.InputStream, $Request.ContentEncoding)
    try {
        $body = $reader.ReadToEnd()
    } finally {
        $reader.Dispose()
    }

    if ([string]::IsNullOrWhiteSpace($body)) {
        return @{}
    }

    $parsed = $body | ConvertFrom-Json -AsHashtable
    if ($null -eq $parsed) {
        return @{}
    }

    return $parsed
}

function Invoke-FolderPicker {
    param(
        [string] $Description
    )

    $dialog = New-Object System.Windows.Forms.FolderBrowserDialog
    $dialog.Description = if ([string]::IsNullOrWhiteSpace($Description)) { "Wybierz folder" } else { $Description }
    $dialog.ShowNewFolderButton = $false

    $result = $dialog.ShowDialog()
    if ($result -ne [System.Windows.Forms.DialogResult]::OK -or [string]::IsNullOrWhiteSpace($dialog.SelectedPath)) {
        return @{
            cancelled = $true
        }
    }

    return @{
        cancelled = $false
        path = $dialog.SelectedPath
    }
}

function Invoke-FilePicker {
    param(
        [string] $Title,
        [string] $Filter
    )

    $dialog = New-Object System.Windows.Forms.OpenFileDialog
    $dialog.Title = if ([string]::IsNullOrWhiteSpace($Title)) { "Wybierz plik" } else { $Title }
    $dialog.Filter = if ([string]::IsNullOrWhiteSpace($Filter)) { "Wszystkie pliki (*.*)|*.*" } else { $Filter }
    $dialog.Multiselect = $false
    $dialog.CheckFileExists = $true

    $result = $dialog.ShowDialog()
    if ($result -ne [System.Windows.Forms.DialogResult]::OK -or [string]::IsNullOrWhiteSpace($dialog.FileName)) {
        return @{
            cancelled = $true
        }
    }

    return @{
        cancelled = $false
        path = $dialog.FileName
    }
}

Write-Host "Agent KSeF local helper starting on port $Port"
Write-Host "Available endpoints: GET /health, POST /pick-folder, POST /pick-file"

try {
    $listener.Start()
} catch {
    Write-Error "Nie udalo sie uruchomic helpera na porcie $Port. $_"
    exit 1
}

try {
    while ($listener.IsListening) {
        $context = $listener.GetContext()
        $request = $context.Request
        $path = ($request.Url.AbsolutePath ?? "/").ToLowerInvariant()

        if ($request.HttpMethod -eq "OPTIONS") {
            Send-EmptyResponse -Context $context
            continue
        }

        try {
            switch ($path) {
                "/health" {
                    if ($request.HttpMethod -ne "GET") {
                        Send-JsonResponse -Context $context -StatusCode 405 -Payload @{
                            ok = $false
                            message = "Use GET for /health."
                        }
                        continue
                    }

                    Send-JsonResponse -Context $context -StatusCode 200 -Payload @{
                        ok = $true
                        status = "ok"
                        port = $Port
                    }
                }
                "/pick-folder" {
                    if ($request.HttpMethod -ne "POST") {
                        Send-JsonResponse -Context $context -StatusCode 405 -Payload @{
                            ok = $false
                            message = "Use POST for /pick-folder."
                        }
                        continue
                    }

                    $body = Read-JsonBody -Request $request
                    $result = Invoke-FolderPicker -Description ([string] ($body["description"] ?? "Wybierz folder"))
                    Send-JsonResponse -Context $context -StatusCode 200 -Payload ($result + @{ ok = $true })
                }
                "/pick-file" {
                    if ($request.HttpMethod -ne "POST") {
                        Send-JsonResponse -Context $context -StatusCode 405 -Payload @{
                            ok = $false
                            message = "Use POST for /pick-file."
                        }
                        continue
                    }

                    $body = Read-JsonBody -Request $request
                    $result = Invoke-FilePicker `
                        -Title ([string] ($body["title"] ?? "Wybierz plik")) `
                        -Filter ([string] ($body["filter"] ?? "Wszystkie pliki (*.*)|*.*"))
                    Send-JsonResponse -Context $context -StatusCode 200 -Payload ($result + @{ ok = $true })
                }
                default {
                    Send-JsonResponse -Context $context -StatusCode 404 -Payload @{
                        ok = $false
                        message = "Nieznany endpoint helpera."
                    }
                }
            }
        } catch {
            Send-JsonResponse -Context $context -StatusCode 500 -Payload @{
                ok = $false
                message = $_.Exception.Message
            }
        }
    }
} finally {
    if ($listener.IsListening) {
        $listener.Stop()
    }

    $listener.Close()
}
