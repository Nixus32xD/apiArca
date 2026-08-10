param(
    [string] $OutputDir = $PSScriptRoot
)

$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Drawing

$W = 1080
$H = 1080

function New-Color {
    param(
        [string] $Hex,
        [int] $Alpha = 255
    )

    $clean = $Hex.TrimStart('#')
    $r = [Convert]::ToInt32($clean.Substring(0, 2), 16)
    $g = [Convert]::ToInt32($clean.Substring(2, 2), 16)
    $b = [Convert]::ToInt32($clean.Substring(4, 2), 16)

    [System.Drawing.Color]::FromArgb($Alpha, $r, $g, $b)
}

function New-BitmapCanvas {
    $bmp = [System.Drawing.Bitmap]::new($W, $H)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $g.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit

    [pscustomobject]@{
        Bitmap = $bmp
        Graphics = $g
    }
}

function Save-Canvas {
    param(
        [System.Drawing.Bitmap] $Bitmap,
        [System.Drawing.Graphics] $Graphics,
        [string] $Path
    )

    $Graphics.Dispose()
    $Bitmap.Save($Path, [System.Drawing.Imaging.ImageFormat]::Png)
    $Bitmap.Dispose()
}

function Fill-Background {
    param(
        [System.Drawing.Graphics] $Graphics,
        [string] $Hex = '#F6F8FB'
    )

    $brush = [System.Drawing.SolidBrush]::new((New-Color $Hex))
    $Graphics.FillRectangle($brush, 0, 0, $W, $H)
    $brush.Dispose()
}

function Draw-RoundRect {
    param(
        [System.Drawing.Graphics] $Graphics,
        [float] $X,
        [float] $Y,
        [float] $Width,
        [float] $Height,
        [float] $Radius,
        [string] $FillHex,
        [string] $StrokeHex = '',
        [float] $StrokeWidth = 1,
        [int] $FillAlpha = 255
    )

    $path = [System.Drawing.Drawing2D.GraphicsPath]::new()
    $diameter = $Radius * 2
    $path.AddArc($X, $Y, $diameter, $diameter, 180, 90)
    $path.AddArc($X + $Width - $diameter, $Y, $diameter, $diameter, 270, 90)
    $path.AddArc($X + $Width - $diameter, $Y + $Height - $diameter, $diameter, $diameter, 0, 90)
    $path.AddArc($X, $Y + $Height - $diameter, $diameter, $diameter, 90, 90)
    $path.CloseFigure()

    if ($FillHex -ne '') {
        $brush = [System.Drawing.SolidBrush]::new((New-Color $FillHex $FillAlpha))
        $Graphics.FillPath($brush, $path)
        $brush.Dispose()
    }

    if ($StrokeHex -ne '') {
        $pen = [System.Drawing.Pen]::new((New-Color $StrokeHex), $StrokeWidth)
        $Graphics.DrawPath($pen, $path)
        $pen.Dispose()
    }

    $path.Dispose()
}

function Draw-Text {
    param(
        [System.Drawing.Graphics] $Graphics,
        [string] $Text,
        [float] $Size,
        [System.Drawing.FontStyle] $Style,
        [string] $Hex,
        [float] $X,
        [float] $Y,
        [float] $Width,
        [float] $Height,
        [string] $Align = 'Near',
        [string] $VAlign = 'Near'
    )

    $font = [System.Drawing.Font]::new('Segoe UI', $Size, $Style, [System.Drawing.GraphicsUnit]::Pixel)
    $brush = [System.Drawing.SolidBrush]::new((New-Color $Hex))
    $format = [System.Drawing.StringFormat]::new()
    $format.Alignment = [System.Drawing.StringAlignment]::$Align
    $format.LineAlignment = [System.Drawing.StringAlignment]::$VAlign
    $format.Trimming = [System.Drawing.StringTrimming]::None

    $rect = [System.Drawing.RectangleF]::new($X, $Y, $Width, $Height)
    $Graphics.DrawString($Text, $font, $brush, $rect, $format)

    $format.Dispose()
    $brush.Dispose()
    $font.Dispose()
}

function Draw-Line {
    param(
        [System.Drawing.Graphics] $Graphics,
        [float] $X1,
        [float] $Y1,
        [float] $X2,
        [float] $Y2,
        [string] $Hex = '#146EF5',
        [float] $Width = 5
    )

    $pen = [System.Drawing.Pen]::new((New-Color $Hex), $Width)
    $pen.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
    $pen.EndCap = [System.Drawing.Drawing2D.LineCap]::ArrowAnchor
    $Graphics.DrawLine($pen, $X1, $Y1, $X2, $Y2)
    $pen.Dispose()
}

function Draw-Header {
    param(
        [System.Drawing.Graphics] $Graphics,
        [string] $Kicker,
        [string] $Title,
        [string] $Subtitle
    )

    Draw-Text $Graphics $Kicker 28 ([System.Drawing.FontStyle]::Bold) '#00A6A6' 72 64 930 44
    Draw-Text $Graphics $Title 58 ([System.Drawing.FontStyle]::Bold) '#0B172A' 72 118 920 140
    Draw-Text $Graphics $Subtitle 32 ([System.Drawing.FontStyle]::Regular) '#334155' 72 255 900 96
}

function Draw-Footer {
    param(
        [System.Drawing.Graphics] $Graphics,
        [string] $Text
    )

    Draw-Text $Graphics $Text 24 ([System.Drawing.FontStyle]::Regular) '#64748B' 72 1000 936 34 'Center'
}

function Draw-Node {
    param(
        [System.Drawing.Graphics] $Graphics,
        [float] $X,
        [float] $Y,
        [float] $Width,
        [float] $Height,
        [string] $Title,
        [string] $Text,
        [string] $Accent = '#146EF5'
    )

    Draw-RoundRect $Graphics $X $Y $Width $Height 24 '#FFFFFF' '#D8E1EC' 2
    Draw-RoundRect $Graphics ($X + 22) ($Y + 22) 56 56 16 $Accent
    Draw-Text $Graphics $Title 28 ([System.Drawing.FontStyle]::Bold) '#0B172A' ($X + 92) ($Y + 20) ($Width - 108) 42
    Draw-Text $Graphics $Text 22 ([System.Drawing.FontStyle]::Regular) '#475569' ($X + 92) ($Y + 66) ($Width - 108) ($Height - 84)
}

function New-Slide01 {
    $canvas = New-BitmapCanvas
    $g = $canvas.Graphics

    $assetPath = Join-Path $PSScriptRoot 'assets\api-integration-visual.png'
    $img = [System.Drawing.Image]::FromFile($assetPath)
    $g.DrawImage($img, 0, 0, $W, $H)
    $img.Dispose()

    $overlay = [System.Drawing.SolidBrush]::new((New-Color '#FFFFFF' 214))
    $g.FillRectangle($overlay, 0, 0, 520, $H)
    $overlay.Dispose()

    Draw-RoundRect $g 70 78 430 50 25 '#E8FBF8' '#B8EFE7' 1
    Draw-Text $g 'Integracion fiscal para software' 24 ([System.Drawing.FontStyle]::Bold) '#087F7A' 92 88 380 32
    Draw-Text $g 'API ARCA' 74 ([System.Drawing.FontStyle]::Bold) '#0B172A' 70 178 430 102
    Draw-Text $g 'Facturacion fiscal integrada para gestion, SaaS y automatizacion comercial.' 35 ([System.Drawing.FontStyle]::Regular) '#334155' 74 315 405 190

    Draw-RoundRect $g 74 600 385 72 22 '#0B172A'
    Draw-Text $g 'Automatizacion' 27 ([System.Drawing.FontStyle]::Bold) '#FFFFFF' 104 618 325 36 'Center'
    Draw-RoundRect $g 74 690 385 72 22 '#146EF5'
    Draw-Text $g 'Integracion' 27 ([System.Drawing.FontStyle]::Bold) '#FFFFFF' 104 708 325 36 'Center'
    Draw-RoundRect $g 74 780 385 72 22 '#00A6A6'
    Draw-Text $g 'Trazabilidad' 27 ([System.Drawing.FontStyle]::Bold) '#FFFFFF' 104 798 325 36 'Center'

    Draw-Footer $g 'Sin logos oficiales. Datos visuales ficticios.'
    Save-Canvas $canvas.Bitmap $g (Join-Path $OutputDir 'slide-01-api-arca-cover.png')
}

function New-Slide02 {
    $canvas = New-BitmapCanvas
    $g = $canvas.Graphics
    Fill-Background $g

    Draw-Header $g 'EL PROBLEMA' 'Cuando la venta y la factura viven separadas' 'Aparecen doble carga, demoras, errores y poca trazabilidad.'

    $cards = @(
        @{ X = 95; Y = 438; T = 'Venta'; D = 'Sistema comercial' },
        @{ X = 398; Y = 438; T = 'Carga manual'; D = 'Planillas y reingreso' },
        @{ X = 701; Y = 438; T = 'Factura'; D = 'Circuito fiscal separado' }
    )

    foreach ($card in $cards) {
        Draw-RoundRect $g $card.X $card.Y 260 170 26 '#FFFFFF' '#D8E1EC' 2
        Draw-RoundRect $g ($card.X + 28) ($card.Y + 26) 56 56 16 '#F59E0B'
        Draw-Text $g $card.T 32 ([System.Drawing.FontStyle]::Bold) '#0B172A' ($card.X + 28) ($card.Y + 92) 210 42
        Draw-Text $g $card.D 22 ([System.Drawing.FontStyle]::Regular) '#64748B' ($card.X + 28) ($card.Y + 132) 210 34
    }

    Draw-Line $g 358 522 388 522 '#CBD5E1' 4
    Draw-Line $g 661 522 691 522 '#CBD5E1' 4

    $problems = @(
        'Datos duplicados entre sistemas',
        'Mayor riesgo de errores de importe o condicion fiscal',
        'Dificil saber que paso cuando una emision falla',
        'Mas tiempo operativo para administracion y soporte'
    )

    $y = 690
    foreach ($item in $problems) {
        Draw-RoundRect $g 124 $y 832 62 20 '#FFFFFF' '#E2E8F0' 1
        Draw-RoundRect $g 150 ($y + 17) 28 28 14 '#F97316'
        Draw-Text $g $item 26 ([System.Drawing.FontStyle]::Regular) '#334155' 202 ($y + 14) 720 34
        $y += 78
    }

    Draw-Footer $g 'La API reduce friccion entre operacion comercial y facturacion.'
    Save-Canvas $canvas.Bitmap $g (Join-Path $OutputDir 'slide-02-problema.png')
}

function New-Slide03 {
    $canvas = New-BitmapCanvas
    $g = $canvas.Graphics
    Fill-Background $g '#F7FAFC'

    Draw-Header $g 'LA RELACION' 'Que hace la API con ARCA' 'Tu sistema opera por HTTP; la API centraliza la logica fiscal.'

    Draw-Node $g 70 430 280 170 'Sistema' "Ventas`nPagos`nTurnos" '#0B172A'
    Draw-Node $g 390 390 300 250 'API ARCA' "Autenticacion`nValidaciones`nIdempotencia`nTrazas" '#00A6A6'
    Draw-Node $g 730 430 280 170 'Servicios' "WSAA`nWSFEv1`nCAE / CAEA" '#146EF5'

    Draw-Line $g 355 515 380 515 '#00A6A6' 6
    Draw-Line $g 695 515 720 515 '#146EF5' 6

    Draw-RoundRect $g 165 705 750 136 28 '#FFFFFF' '#D8E1EC' 2
    Draw-Text $g 'Resultado' 27 ([System.Drawing.FontStyle]::Bold) '#00A6A6' 210 720 160 35
    Draw-Text $g 'Comprobante autorizado, rechazado o pendiente de conciliacion, con estado trazable.' 27 ([System.Drawing.FontStyle]::Regular) '#334155' 210 760 660 70

    Draw-RoundRect $g 118 878 844 58 18 '#E8FBF8' '#B8EFE7' 1
    Draw-Text $g 'No se afirma certificacion oficial: es una integracion tecnica con servicios fiscales.' 23 ([System.Drawing.FontStyle]::Regular) '#087F7A' 146 894 788 30 'Center'

    Draw-Footer $g 'Flujo simplificado para comunicacion comercial.'
    Save-Canvas $canvas.Bitmap $g (Join-Path $OutputDir 'slide-03-relacion-api-arca.png')
}

function New-Slide04 {
    $canvas = New-BitmapCanvas
    $g = $canvas.Graphics
    Fill-Background $g

    Draw-Header $g 'BENEFICIOS' 'Automatizacion, control y menos errores' 'La facturacion fiscal se integra al proceso de negocio.'

    $benefits = @(
        @{ X = 78; Y = 410; T = 'Automatizacion'; D = 'Venta -> comprobante fiscal sin pasos manuales.'; C = '#00A6A6' },
        @{ X = 563; Y = 410; T = 'Menos carga manual'; D = 'Menos copia de datos y menos tareas repetitivas.'; C = '#146EF5' },
        @{ X = 78; Y = 620; T = 'Integracion'; D = 'API HTTP para SaaS, ERP y sistemas propios.'; C = '#7C3AED' },
        @{ X = 563; Y = 620; T = 'Trazabilidad'; D = 'Logs, intentos, eventos, estados y trace_id.'; C = '#0B172A' },
        @{ X = 320; Y = 830; T = 'Menos errores'; D = 'Validaciones, idempotencia y conciliacion segura.'; C = '#22C55E' }
    )

    foreach ($b in $benefits) {
        Draw-RoundRect $g $b.X $b.Y 440 152 26 '#FFFFFF' '#D8E1EC' 2
        Draw-RoundRect $g ($b.X + 26) ($b.Y + 28) 58 58 17 $b.C
        Draw-Text $g $b.T 29 ([System.Drawing.FontStyle]::Bold) '#0B172A' ($b.X + 106) ($b.Y + 28) 300 38
        Draw-Text $g $b.D 23 ([System.Drawing.FontStyle]::Regular) '#475569' ($b.X + 106) ($b.Y + 70) 300 58
    }

    Save-Canvas $canvas.Bitmap $g (Join-Path $OutputDir 'slide-04-beneficios.png')
}

function New-Slide05 {
    $canvas = New-BitmapCanvas
    $g = $canvas.Graphics
    Fill-Background $g '#F8FAFC'

    Draw-Header $g 'CASOS DE USO' 'Donde encaja API ARCA' 'Para plataformas que venden, cobran o registran operaciones comerciales.'

    $items = @(
        @{ X = 80; Y = 405; T = 'SaaS multiempresa'; D = 'Facturacion por tenant o cliente.'; C = '#146EF5' },
        @{ X = 390; Y = 405; T = 'ERP / gestion'; D = 'Emision desde el flujo operativo.'; C = '#00A6A6' },
        @{ X = 700; Y = 405; T = 'Turnos'; D = 'Comprobante al cerrar atencion.'; C = '#22C55E' },
        @{ X = 80; Y = 650; T = 'Pagos'; D = 'Factura vinculada al cobro.'; C = '#7C3AED' },
        @{ X = 390; Y = 650; T = 'Backoffice'; D = 'IVA ventas, compras y control.'; C = '#F59E0B' },
        @{ X = 700; Y = 650; T = 'Contingencia'; D = 'CAEA y reporte posterior.'; C = '#0B172A' }
    )

    foreach ($item in $items) {
        Draw-RoundRect $g $item.X $item.Y 270 190 26 '#FFFFFF' '#D8E1EC' 2
        Draw-RoundRect $g ($item.X + 28) ($item.Y + 28) 62 62 18 $item.C
        Draw-Text $g $item.T 27 ([System.Drawing.FontStyle]::Bold) '#0B172A' ($item.X + 28) ($item.Y + 96) 218 36
        Draw-Text $g $item.D 21 ([System.Drawing.FontStyle]::Regular) '#475569' ($item.X + 28) ($item.Y + 130) 218 54
    }

    Draw-RoundRect $g 160 908 760 62 20 '#0B172A'
    Draw-Text $g 'Una base fiscal integrable, auditable y mantenible.' 27 ([System.Drawing.FontStyle]::Bold) '#FFFFFF' 200 924 680 34 'Center'

    Save-Canvas $canvas.Bitmap $g (Join-Path $OutputDir 'slide-05-casos-de-uso.png')
}

New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null

New-Slide01
New-Slide02
New-Slide03
New-Slide04
New-Slide05

Write-Host "Slides generated in $OutputDir"
