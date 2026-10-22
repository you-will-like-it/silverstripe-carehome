<% include YouWillLikeIT\\CareHome\\PortalHead %>
<section class="ch-card mt-6 p-6 text-center">
    <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-rose-100 text-rose-700">
        <svg aria-hidden="true" class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v5m0 3h.01"/></svg>
    </div>
    <p class="text-lg font-semibold">$Message</p>
    <a href="$Link" class="ch-btn-ghost mt-6"><%t CareHomePortal.BACK_HOME "Na početnu" %></a>
</section>
<% if $Worker %><% include YouWillLikeIT\\CareHome\\ScanButton %><% end_if %>
<% include YouWillLikeIT\\CareHome\\PortalFoot %>
