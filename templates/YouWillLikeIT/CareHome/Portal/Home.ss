<% include YouWillLikeIT\\CareHome\\PortalHead %>
<div class="mb-4 flex flex-wrap items-center gap-2">
    <h1 class="mr-auto text-2xl font-bold">$Title</h1>
    <% if $CurrentShift %>
        <span class="ch-badge bg-brand-100 text-brand-800"><%t CareHomePortal.SHIFT "Smjena" %>: $CurrentShift</span>
    <% end_if %>
    <a href="$Link('handover')" class="ch-btn-ghost min-h-10 text-sm"><%t CareHomePortal.HANDOVER "Primopredaja" %></a>
</div>

<% if $Zones %>
<div class="mb-4 flex flex-wrap gap-2">
    <% loop $Zones %>
        <span class="ch-badge bg-white text-slate-700 ring-1 ring-slate-200">$Breadcrumb</span>
    <% end_loop %>
</div>
<% end_if %>

<% if $Residents %>
<ul class="space-y-3">
    <% loop $Residents %>
    <li>
        <a href="$Link" class="ch-card flex items-center gap-4 p-4 active:bg-slate-50">
            <div class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-xl bg-slate-100 text-slate-700">
                <span class="text-[10px] font-medium uppercase leading-none"><%t CareHomePortal.ROOM_SHORT "Soba" %></span>
                <span class="text-lg font-bold leading-tight">$RoomNumber</span>
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <span class="truncate text-lg font-semibold">$Patient.FirstName $Patient.LastName</span>
                    <% if $HasMedicalAlerts %>
                        <span class="ch-badge bg-rose-100 text-rose-700" title="<%t CareHomePortal.ALERTS "Alergije / dijagnoze" %>">!</span>
                    <% end_if %>
                </div>
                <div class="truncate text-sm text-slate-500">$BedLabel · $ZoneTitle</div>
                <% if $IsAbsent %>
                    <span class="ch-badge mt-1 bg-amber-100 text-amber-800"><%t CareHomePortal.ABSENT "Privremeno odsutan" %></span>
                <% else_if $Total %>
                    <div class="mt-2 flex items-center gap-2">
                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-200">
                            <div class="h-full rounded-full bg-emerald-500" style="width: {$Percent}%"></div>
                        </div>
                        <span class="text-xs font-semibold text-slate-600">$Closed/$Total</span>
                        <% if $Overdue %>
                            <span class="ch-badge bg-rose-600 text-white">$Overdue <%t CareHomePortal.OVERDUE_SHORT "kasni" %></span>
                        <% end_if %>
                    </div>
                <% end_if %>
            </div>
            <svg aria-hidden="true" class="h-5 w-5 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 6 6 6-6 6"/></svg>
        </a>
    </li>
    <% end_loop %>
</ul>
<% else %>
<div class="ch-card p-6 text-center text-slate-600">
    <%t CareHomePortal.NO_RESIDENTS "Nema štićenika u vašim zonama. Obratite se administratoru." %>
</div>
<% end_if %>

<% include YouWillLikeIT\\CareHome\\ScanButton %>
<% include YouWillLikeIT\\CareHome\\PortalFoot %>
