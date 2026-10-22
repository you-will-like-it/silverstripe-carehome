<% include YouWillLikeIT\\CareHome\\PortalHead %>
<section class="ch-card mt-6 p-6 text-center">
    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-amber-100 text-amber-700">
        <svg aria-hidden="true" class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
    </div>
    <h1 class="text-2xl font-bold">$Patient.FirstName $Patient.LastName</h1>
    <p class="mt-2 text-lg font-semibold text-amber-700"><%t CareHomePortal.ABSENT "Privremeno odsutan" %></p>
</section>
<% include YouWillLikeIT\\CareHome\\ScanButton %>
<% include YouWillLikeIT\\CareHome\\PortalFoot %>
