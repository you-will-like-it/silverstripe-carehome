<% loop $Days %>
<section class="ch-card mb-4 break-inside-avoid p-4 print:shadow-none print:ring-black/20">
    <h2 class="mb-3 text-xl font-bold capitalize">$Date.Format('EEEE, dd.MM.yyyy')</h2>
    <div class="grid gap-4 sm:grid-cols-2">
        <% loop $Meals %>
        <div>
            <h3 class="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500">$Label</h3>
            <% if $HasMenu %>
            <table class="w-full text-left">
                <tbody class="divide-y divide-slate-100">
                    <% loop $Options %>
                    <tr>
                        <td class="w-16 py-2 pr-3 text-2xl font-bold tabular-nums">{$Count}×</td>
                        <td class="py-2">
                            <span class="font-semibold">$OptionName</span>
                            <% if $Description %><span class="block text-sm text-slate-600">$Description</span><% end_if %>
                        </td>
                    </tr>
                    <% end_loop %>
                </tbody>
            </table>
            <p class="mt-2 text-sm text-slate-600">
                <%t CareHomePortal.K_TOTAL "Ukupno odabrano: {count}" count=$Selected %>
                <% if $Unselected %> · <span class="font-semibold text-amber-700"><%t CareHomePortal.K_UNSELECTED "bez izbora: {count}" count=$Unselected %></span><% end_if %>
            </p>
            <% else %>
            <p class="text-slate-500"><%t CareHomePortal.NO_MENU "Jelovnik još nije unesen." %></p>
            <% end_if %>
        </div>
        <% end_loop %>
    </div>
</section>
<% end_loop %>

<% if $DietNotes %>
<section class="ch-card break-inside-avoid p-4">
    <h2 class="mb-3 text-xl font-bold"><%t CareHomePortal.K_DIET "Posebna ishrana" %></h2>
    <table class="w-full text-left text-sm">
        <thead class="text-xs uppercase text-slate-500">
            <tr>
                <th class="py-1 pr-3"><%t CareHomePortal.ROOM_SHORT "Soba" %></th>
                <th class="py-1 pr-3"><%t CareHomePortal.K_NAME "Štićenik" %></th>
                <th class="py-1"><%t CareHomePortal.DIET "Ishrana" %></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <% loop $DietNotes %>
            <tr>
                <td class="py-2 pr-3 font-semibold">$Room</td>
                <td class="py-2 pr-3">$Name</td>
                <td class="py-2">
                    <% if $Tags %><span class="font-semibold">$Tags</span><% end_if %>
                    <% if $Requirements %><span class="block whitespace-pre-line text-slate-700">$Requirements</span><% end_if %>
                </td>
            </tr>
            <% end_loop %>
        </tbody>
    </table>
</section>
<% end_if %>
