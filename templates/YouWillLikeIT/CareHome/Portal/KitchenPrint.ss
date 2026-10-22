<!doctype html>
<html lang="$ContentLocale">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>$Title</title>
    $ViteTags
</head>
<body class="bg-white p-6 text-slate-900 print:p-0">
    <div class="mx-auto max-w-4xl">
        <header class="mb-4 flex items-end justify-between border-b border-slate-300 pb-2">
            <div>
                <h1 class="text-2xl font-bold"><%t CareHomePortal.K_PRINT_TITLE "Kuhinja – zbirni pregled obroka" %></h1>
                <p class="text-sm text-slate-600"><%t CareHomePortal.K_GENERATED "Generisano" %>: $GeneratedAt.Format('dd.MM.yyyy HH:mm')</p>
            </div>
            <button type="button" onclick="window.print()" class="ch-btn-primary ch-no-print"><%t CareHomePortal.PRINT "Štampaj" %></button>
        </header>
        <% include YouWillLikeIT\\CareHome\\KitchenSummary %>
    </div>
</body>
</html>
