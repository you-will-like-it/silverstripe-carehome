<!doctype html>
<html lang="$i18nLocale">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>$Title</title>
    <style>
        @page { size: A4; margin: 10mm; }
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 10mm; color: #000; }
        .toolbar { margin-bottom: 8mm; display: flex; gap: 1rem; align-items: center; }
        .toolbar button { padding: .6rem 1.2rem; font-size: 1rem; border-radius: .5rem; border: 0; background: #155e63; color: #fff; cursor: pointer; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6mm; }
        .label { border: 1px dashed #94a3b8; border-radius: 3mm; padding: 4mm; text-align: center; break-inside: avoid; page-break-inside: avoid; }
        .label svg { width: 45mm; height: 45mm; display: block; margin: 0 auto 2mm; }
        .room { font-size: 14pt; font-weight: 700; }
        .bed { font-size: 11pt; }
        .zone { font-size: 8pt; color: #475569; margin-top: 1mm; }
        @media print { .toolbar { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <strong>$Title</strong>
        <button type="button" onclick="window.print()"><%t CareHomePortal.PRINT "Štampaj" %></button>
    </div>
    <div class="grid">
        <% loop $Labels %>
        <div class="label">
            $Svg
            <div class="room"><%t CareHomePortal.ROOM_SHORT "Soba" %> $RoomNumber</div>
            <div class="bed">$BedLabel</div>
            <div class="zone">$ZoneTitle</div>
        </div>
        <% end_loop %>
    </div>
</body>
</html>
