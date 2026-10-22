<% include YouWillLikeIT\\CareHome\\PortalHead %>
<div class="mb-4 flex items-center gap-2">
    <a href="$Link" class="ch-btn-ghost min-h-10 px-3" aria-label="<%t CareHomePortal.BACK_HOME "Na početnu" %>">←</a>
    <h1 class="text-2xl font-bold">$Title</h1>
    <% if $CurrentShift %><span class="ch-badge ml-auto bg-brand-100 text-brand-800">$CurrentShift</span><% end_if %>
</div>

<% loop $ZoneBlocks %>
<section class="ch-card mb-4 p-4">
    <h2 class="mb-2 font-semibold">$Breadcrumb</h2>
    <% if $CriticalNotes %>
        <ul class="space-y-2">
            <% loop $CriticalNotes %>
            <li class="rounded-xl bg-rose-50 p-3 ring-1 ring-rose-200">
                <p class="text-xs text-rose-700">$Created.Format('HH:mm') · $Patient.FirstName $Patient.LastName · $CreatedBy.FirstName</p>
                <p class="whitespace-pre-line text-rose-900">$NoteText</p>
            </li>
            <% end_loop %>
        </ul>
    <% else %>
        <p class="text-sm text-slate-500"><%t CareHomePortal.NO_CRITICAL "Nema kritičnih napomena u ovoj smjeni." %></p>
    <% end_if %>
</section>
<% end_loop %>

<% if $HasShift %>
<form method="post" action="$FormAction" class="ch-card mb-6 space-y-3 p-4">
    <input type="hidden" name="$SecurityTokenName" value="$SecurityTokenValue">
    <div>
        <label class="ch-label" for="ho-zone"><%t CareHomePortal.ZONE "Zona" %></label>
        <select id="ho-zone" name="zone" class="ch-input" required>
            <% loop $ZoneOptions %><option value="$Value">$Label</option><% end_loop %>
        </select>
    </div>
    <div>
        <label class="ch-label" for="ho-receiver"><%t CareHomePortal.RECEIVER "Smjenu preuzima" %></label>
        <select id="ho-receiver" name="receiver" class="ch-input">
            <option value="0">—</option>
            <% loop $Receivers %><option value="$Value">$Label</option><% end_loop %>
        </select>
    </div>
    <div>
        <label class="ch-label" for="ho-notes"><%t CareHomePortal.GENERAL_NOTES "Opšte napomene" %></label>
        <textarea id="ho-notes" name="notes" rows="4" class="ch-input"></textarea>
    </div>
    <button type="submit" class="ch-btn-primary w-full"><%t CareHomePortal.HANDOVER_SAVE "Evidentiraj primopredaju" %></button>
</form>
<% end_if %>

<% if $RecentLogs %>
<h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500"><%t CareHomePortal.RECENT_HANDOVERS "Zadnje primopredaje" %></h2>
<ul class="ch-card divide-y divide-slate-100">
    <% loop $RecentLogs %>
    <li class="p-3">
        <p class="text-sm text-slate-500">$ShiftDate.Format('dd.MM.') · $ShiftLabel · $Zone.Title</p>
        <p class="font-medium">$HandedOverBy.FirstName → <% if $ReceivedByID %>$ReceivedBy.FirstName<% else %>—<% end_if %>
            <% if $CriticalNoteCount %><span class="ch-badge ml-1 bg-rose-600 text-white">$CriticalNoteCount</span><% end_if %></p>
        <% if $GeneralNotes %><p class="whitespace-pre-line text-sm text-slate-700">$GeneralNotes</p><% end_if %>
    </li>
    <% end_loop %>
</ul>
<% end_if %>
<% include YouWillLikeIT\\CareHome\\PortalFoot %>
