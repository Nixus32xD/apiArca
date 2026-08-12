param(
    [string] $OutputDir = $PSScriptRoot
)

$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Drawing

$W = 1080
$H = 1080

$Glyph = @{
    a_acute = [string] [char] 0x00E1
    e_acute = [string] [char] 0x00E9
    i_acute = [string] [char] 0x00ED
    o_acute = [string] [char] 0x00F3
    u_acute = [string] [char] 0x00FA
    cap_a_acute = [string] [char] 0x00C1
    cap_e_acute = [string] [char] 0x00C9
    cap_i_acute = [string] [char] 0x00CD
    cap_o_acute = [string] [char] 0x00D3
    cap_u_acute = [string] [char] 0x00DA
}

function T {
    param([string] $Text)

    $Text = $Text.Replace('{a}', $Glyph.a_acute)
    $Text = $Text.Replace('{e}', $Glyph.e_acute)
    $Text = $Text.Replace('{i}', $Glyph.i_acute)
    $Text = $Text.Replace('{o}', $Glyph.o_acute)
    $Text = $Text.Replace('{u}', $Glyph.u_acute)
    $Text = $Text.Replace('{A}', $Glyph.cap_a_acute)
    $Text = $Text.Replace('{E}', $Glyph.cap_e_acute)
    $Text = $Text.Replace('{I}', $Glyph.cap_i_acute)
    $Text = $Text.Replace('{O}', $Glyph.cap_o_acute)
    $Text = $Text.Replace('{U}', $Glyph.cap_u_acute)

    $Text
}

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
    Draw-Text $Graphics $Title 26 ([System.Drawing.FontStyle]::Bold) '#0B172A' ($X + 92) ($Y + 18) ($Width - 108) 58
    Draw-Text $Graphics $Text 20 ([System.Drawing.FontStyle]::Regular) '#475569' ($X + 92) ($Y + 78) ($Width - 108) ($Height - 96)
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
    Draw-Text $g (T 'Integraci{o}n fiscal para software') 24 ([System.Drawing.FontStyle]::Bold) '#087F7A' 92 88 380 32
    Draw-Text $g "API Fiscal`npara ARCA" 66 ([System.Drawing.FontStyle]::Bold) '#0B172A' 70 164 430 170
    Draw-Text $g (T 'Facturaci{o}n fiscal integrada para sistemas de gesti{o}n, SaaS y automatizaci{o}n comercial.') 33 ([System.Drawing.FontStyle]::Regular) '#334155' 74 340 405 210

    Draw-RoundRect $g 74 600 385 72 22 '#0B172A'
    Draw-Text $g (T 'Automatizaci{o}n') 27 ([System.Drawing.FontStyle]::Bold) '#FFFFFF' 104 618 325 36 'Center'
    Draw-RoundRect $g 74 690 385 72 22 '#146EF5'
    Draw-Text $g (T 'Integraci{o}n') 27 ([System.Drawing.FontStyle]::Bold) '#FFFFFF' 104 708 325 36 'Center'
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
        (T 'Mayor riesgo de errores de importe o condici{o}n fiscal'),
        (T 'Dif{i}cil saber qu{e} pas{o} cuando una emisi{o}n falla'),
        (T 'M{a}s tiempo operativo para administraci{o}n y soporte')
    )

    $y = 690
    foreach ($item in $problems) {
        Draw-RoundRect $g 124 $y 832 62 20 '#FFFFFF' '#E2E8F0' 1
        Draw-RoundRect $g 150 ($y + 17) 28 28 14 '#F97316'
        Draw-Text $g $item 26 ([System.Drawing.FontStyle]::Regular) '#334155' 202 ($y + 14) 720 34
        $y += 78
    }

    Draw-Footer $g (T 'La integraci{o}n reduce fricci{o}n entre operaci{o}n comercial y facturaci{o}n.')
    Save-Canvas $canvas.Bitmap $g (Join-Path $OutputDir 'slide-02-problema.png')
}

function New-Slide03 {
    $canvas = New-BitmapCanvas
    $g = $canvas.Graphics
    Fill-Background $g '#F7FAFC'

    Draw-Header $g (T 'LA RELACI{O}N') (T 'Qu{e} hace la integraci{o}n con ARCA') (T 'Tu sistema opera por HTTP; la API centraliza la l{o}gica fiscal.')

    Draw-Node $g 60 430 280 190 'Sistema' (T "Ventas`nPagos`nTurnos") '#0B172A'
    Draw-Node $g 385 382 310 268 'API Fiscal' (T "Multiempresa`nA/B/C autom{a}tico`nIdempotencia`nConciliaci{o}n") '#00A6A6'
    Draw-Node $g 740 410 300 230 'Servicios ARCA' (T "WSAA`nWSFEv1`nAutorizaci{o}n:`nCAE / CAEA") '#146EF5'

    Draw-Line $g 345 525 375 525 '#00A6A6' 6
    Draw-Line $g 700 525 730 525 '#146EF5' 6

    Draw-RoundRect $g 165 705 750 136 28 '#FFFFFF' '#D8E1EC' 2
    Draw-Text $g 'Resultado' 27 ([System.Drawing.FontStyle]::Bold) '#00A6A6' 210 720 160 35
    Draw-Text $g (T 'Comprobante autorizado, rechazado o pendiente de conciliaci{o}n, con estado trazable.') 27 ([System.Drawing.FontStyle]::Regular) '#334155' 210 760 660 70

    Draw-RoundRect $g 118 872 844 72 18 '#E8FBF8' '#B8EFE7' 1
    Draw-Text $g (T 'Integraci{o}n t{e}cnica con servicios de ARCA. No constituye una API oficial de ARCA.') 21 ([System.Drawing.FontStyle]::Regular) '#087F7A' 146 888 788 42 'Center'

    Draw-Footer $g (T 'Flujo simplificado para comunicaci{o}n comercial.')
    Save-Canvas $canvas.Bitmap $g (Join-Path $OutputDir 'slide-03-relacion-api-arca.png')
}

function New-Slide04 {
    $canvas = New-BitmapCanvas
    $g = $canvas.Graphics
    Fill-Background $g

    Draw-Header $g 'BENEFICIOS' (T 'Automatizaci{o}n, control y reintentos seguros') (T 'La facturaci{o}n fiscal se integra al proceso de negocio.')

    $benefits = @(
        @{ X = 78; Y = 410; T = (T 'Automatizaci{o}n'); D = 'Venta -> comprobante fiscal sin pasos manuales.'; C = '#00A6A6' },
        @{ X = 563; Y = 410; T = 'Menos carga manual'; D = 'Menos copia de datos y menos tareas repetitivas.'; C = '#146EF5' },
        @{ X = 78; Y = 620; T = (T 'Integraci{o}n'); D = 'API HTTP para SaaS, ERP y sistemas propios.'; C = '#7C3AED' },
        @{ X = 563; Y = 620; T = 'Trazabilidad'; D = 'Logs, intentos, eventos, estados y trace_id.'; C = '#0B172A' },
        @{ X = 320; Y = 830; T = 'Reintentos seguros'; D = (T 'Idempotencia y conciliaci{o}n antes de volver a emitir.'); C = '#22C55E' }
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

    Draw-Header $g 'CASOS DE USO' (T 'D{o}nde encaja la integraci{o}n') 'Para plataformas que venden, cobran o registran operaciones comerciales.'

    $items = @(
        @{ X = 80; Y = 405; T = 'SaaS'; D = 'Multiempresa / tenant.'; C = '#146EF5' },
        @{ X = 390; Y = 405; T = (T 'ERP / Gesti{o}n'); D = (T 'Emisi{o}n integrada.'); C = '#00A6A6' },
        @{ X = 700; Y = 405; T = "Turnos /`nServicios"; D = 'Cierre de servicio.'; C = '#22C55E' },
        @{ X = 80; Y = 650; T = 'Pagos'; D = 'Factura vinculada.'; C = '#7C3AED' },
        @{ X = 390; Y = 650; T = "Backoffice /`nReporting"; D = 'IVA y control.'; C = '#F59E0B' },
        @{ X = 700; Y = 650; T = 'Contingencia'; D = 'CAEA y reporte.'; C = '#0B172A' }
    )

    foreach ($item in $items) {
        Draw-RoundRect $g $item.X $item.Y 270 190 26 '#FFFFFF' '#D8E1EC' 2
        Draw-RoundRect $g ($item.X + 28) ($item.Y + 28) 62 62 18 $item.C
        Draw-Text $g $item.T 25 ([System.Drawing.FontStyle]::Bold) '#0B172A' ($item.X + 28) ($item.Y + 92) 218 58
        Draw-Text $g $item.D 20 ([System.Drawing.FontStyle]::Regular) '#475569' ($item.X + 28) ($item.Y + 150) 218 32
    }

    Draw-RoundRect $g 160 908 760 62 20 '#0B172A'
    Draw-Text $g 'Una base fiscal integrable, auditable y mantenible.' 27 ([System.Drawing.FontStyle]::Bold) '#FFFFFF' 200 924 680 34 'Center'
    Draw-Text $g 'Repositorio: github.com/Nixus32xD/apiArca' 22 ([System.Drawing.FontStyle]::Regular) '#64748B' 160 985 760 34 'Center'

    Save-Canvas $canvas.Bitmap $g (Join-Path $OutputDir 'slide-05-casos-de-uso.png')
}

New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null

New-Slide01
New-Slide02
New-Slide03
New-Slide04
New-Slide05

Write-Host "Slides generated in $OutputDir"
