<% with $FlashMessage %>
<div role="status" class="mb-4 flex items-start gap-3 rounded-2xl p-4 text-base font-medium ch-no-print <% if $type == 'error' %>bg-rose-50 text-rose-800 ring-1 ring-rose-200<% else %>bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200<% end_if %>"
     x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)">
    <span class="flex-1">$message</span>
    <button type="button" class="text-xl leading-none opacity-60" @click="show = false" aria-label="<%t CareHomePortal.CLOSE "Zatvori" %>">×</button>
</div>
<% end_with %>
