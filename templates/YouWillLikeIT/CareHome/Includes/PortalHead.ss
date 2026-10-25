<!doctype html>
<html lang="$ContentLocale">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#155e63">
    <meta name="format-detection" content="telephone=no">
    <title>$Title · <%t CareHomePortal.APP "Dom za njegu" %></title>
    $ViteTags
</head>
<body class="min-h-dvh">
<% if $Worker %>
<header class="sticky top-0 z-30 bg-brand-800 text-white shadow ch-no-print">
    <div class="mx-auto flex max-w-3xl items-center gap-3 px-4 py-3">
        <a href="$Link" class="flex items-center gap-2 text-lg font-bold tracking-tight">
            <svg aria-hidden="true" class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 11.5 12 4l9 7.5"/><path d="M5 10v10h14V10"/><path d="M12 13.5c-1.2-1.3-3.5-.6-3.5 1.2 0 1.6 2.1 2.9 3.5 3.8 1.4-.9 3.5-2.2 3.5-3.8 0-1.8-2.3-2.5-3.5-1.2Z"/></svg>
            <span><%t CareHomePortal.APP "Dom za njegu" %></span>
        </a>
        <span class="ml-auto truncate text-sm text-brand-100">$Worker.FirstName $Worker.Surname</span>
        <% loop $PortalNavLinks %>
        <a href="$Link" class="rounded-lg bg-white/10 px-3 py-2 text-sm font-semibold hover:bg-white/20">$Title<% if $Badge %> <span class="ch-badge bg-rose-600 text-white">$Badge</span><% end_if %></a>
        <% end_loop %>
        <% if not $IsCmsSession %>
        <form method="post" action="$Link('logout')">
            <input type="hidden" name="$SecurityTokenName" value="$SecurityTokenValue">
            <button type="submit" class="rounded-lg bg-white/10 px-3 py-2 text-sm font-semibold hover:bg-white/20">
                <%t CareHomePortal.LOGOUT "Odjava" %>
            </button>
        </form>
        <% end_if %>
    </div>
</header>
<% end_if %>
<main class="mx-auto max-w-3xl px-4 pb-32 pt-4">
<% include YouWillLikeIT\\CareHome\\Flash %>
