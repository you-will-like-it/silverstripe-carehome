<!doctype html>
<html lang="$i18nLocale">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>$Title – $Patient.FirstName $Patient.LastName</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #f1f5f9; color: #0f172a; margin: 0; padding: 2rem 1rem; }
        .card { max-width: 28rem; margin: 0 auto; background: #fff; border-radius: 1rem; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        h1 { font-size: 1.35rem; margin: 0 0 .25rem; }
        p.sub { margin: 0 0 1.25rem; color: #475569; }
        label { display: block; font-size: .875rem; font-weight: 600; margin: .75rem 0 .25rem; }
        input { width: 100%; box-sizing: border-box; padding: .7rem .8rem; border: 1px solid #cbd5e1; border-radius: .6rem; font-size: 1rem; }
        button { margin-top: 1.25rem; width: 100%; padding: .8rem; border: 0; border-radius: .6rem; background: #155e63; color: #fff; font-size: 1rem; font-weight: 600; cursor: pointer; }
        .hint { font-size: .8rem; color: #64748b; margin-top: .75rem; }
    </style>
</head>
<body>
<form class="card" method="get" action="$Action" target="_blank">
    <h1>$Title</h1>
    <p class="sub">$Patient.FirstName $Patient.LastName</p>
    <label for="from"><%t CareHomeReport.FROM "Od" %></label>
    <input id="from" type="date" name="from" value="$DefaultFrom" required>
    <label for="to"><%t CareHomeReport.TO "Do" %></label>
    <input id="to" type="date" name="to" value="$DefaultTo" required>
    <button type="submit"><%t CareHomeReport.GENERATE "Generiši PDF" %></button>
    <p class="hint"><%t CareHomeReport.MAX "Najduži period: {days} dana." days=$MaxDays %></p>
</form>
</body>
</html>
