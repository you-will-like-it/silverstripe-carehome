<% include YouWillLikeIT\\CareHome\\PortalHead %>
<div class="mb-4 flex flex-wrap items-center gap-2 ch-no-print">
    <h1 class="mr-auto text-2xl font-bold">$Title</h1>
    <a href="$PrintLink" target="_blank" rel="noopener" class="ch-btn-ghost min-h-10 text-sm"><%t CareHomePortal.PRINT "Štampaj" %></a>
</div>
<nav class="mb-4 grid grid-cols-3 gap-1 rounded-xl bg-white p-1 ring-1 ring-slate-200 ch-no-print">
    <% loop $Ranges %>
        <a href="$Link" class="rounded-lg px-2 py-2 text-center text-sm font-semibold <% if $Active %>bg-brand-700 text-white<% else %>text-slate-600<% end_if %>">$Label</a>
    <% end_loop %>
</nav>
<% include YouWillLikeIT\\CareHome\\KitchenSummary %>
<% include YouWillLikeIT\\CareHome\\PortalFoot %>
