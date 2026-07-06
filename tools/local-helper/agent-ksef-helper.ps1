param(
    [int] $Port = 8765
)

$scriptDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$pythonScript = Join-Path $scriptDirectory 'agent-ksef-helper.py'

python $pythonScript --port $Port
