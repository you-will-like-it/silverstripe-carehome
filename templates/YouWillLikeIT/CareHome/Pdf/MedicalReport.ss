<style>
    body { font-family: dejavusans, sans-serif; font-size: 9pt; color: #111; }
    h1 { font-size: 15pt; margin: 0 0 2mm; }
    h2 { font-size: 11pt; margin: 6mm 0 2mm; padding-bottom: 1mm; border-bottom: 0.4mm solid #155e63; color: #155e63; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 0.2mm solid #cbd5e1; padding: 1.2mm 1.6mm; vertical-align: top; }
    th { background: #f1f5f9; text-align: left; font-weight: bold; }
    table.info th { width: 38mm; }
    .abn { color: #b91c1c; font-weight: bold; }
    .done { color: #047857; }
    .skipped { color: #b91c1c; font-weight: bold; }
    .missed { color: #b45309; font-weight: bold; }
    tr.critical td { background: #fef2f2; }
    .muted { color: #64748b; }
    .alert { border: 0.3mm solid #fca5a5; background: #fef2f2; padding: 2mm; }
    .num { text-align: center; }
</style>

<h1><%t CareHomeReport.TITLE "Ljekarski izvještaj" %></h1>
<p class="muted"><%t CareHomeReport.PERIOD "Period" %>: $From.Format('dd.MM.yyyy') – $To.Format('dd.MM.yyyy')</p>

<h2><%t CareHomeReport.PATIENT "Podaci o štićeniku" %></h2>
<table class="info">
    <tr><th><%t CareHomeReport.NAME "Ime i prezime" %></th><td><strong>$Patient.FirstName $Patient.LastName</strong></td></tr>
    <tr><th><%t CareHomeReport.DOB "Datum rođenja" %></th><td><% if $Patient.DateOfBirth %>$Patient.DateOfBirth.Format('dd.MM.yyyy')<% if $Patient.Age %> ($Patient.Age <%t CareHomeReport.YEARS "god." %>)<% end_if %><% end_if %></td></tr>
    <tr><th><%t CareHomeReport.JMBG "JMBG" %></th><td>$Patient.JMBG</td></tr>
    <tr><th><%t CareHomeReport.ADMISSION "Datum prijema" %></th><td><% if $Patient.AdmissionDate %>$Patient.AdmissionDate.Format('dd.MM.yyyy')<% end_if %></td></tr>
    <tr><th><%t CareHomeReport.BED "Smještaj" %></th><td>$Patient.BedTitle</td></tr>
    <tr><th><%t CareHomeReport.STATUS "Status" %></th><td>$Patient.StatusLabel</td></tr>
    <tr><th><%t CareHomeReport.EMERGENCY "Hitni kontakt" %></th><td>$Patient.EmergencyContactName <% if $Patient.EmergencyContactRelation %>($Patient.EmergencyContactRelation)<% end_if %> $Patient.EmergencyContactPhone</td></tr>
</table>

<% if $Patient.MedicalAlerts %>
<h2><%t CareHomeReport.ALERTS "Alergije i dijagnoze" %></h2>
<div class="alert">$Patient.MedicalAlerts.XML</div>
<% end_if %>

<% if $Patient.DietaryRequirements %>
<h2><%t CareHomeReport.DIET "Ishrana" %></h2>
<p>$Patient.DietaryRequirements</p>
<% end_if %>

<% if $Patient.AdminNotes %>
<h2><%t CareHomeReport.ADMIN_NOTES "Napomene pri prijemu" %></h2>
<div>$Patient.AdminNotes</div>
<% end_if %>

<h2><%t CareHomeReport.VITALS "Vitalni parametri" %></h2>
<% with $Vitals %>
    <% if $HasRows %>
    <table>
        <thead>
            <tr>
                <th><%t CareHomeReport.MEASURED "Vrijeme mjerenja" %></th>
                <% loop $Columns %><th class="num">$Title<% if $Unit %><br><span class="muted">$Unit</span><% end_if %></th><% end_loop %>
            </tr>
        </thead>
        <tbody>
            <% loop $Rows %>
            <tr>
                <td>$MeasuredAt.Format('dd.MM.yyyy HH:mm')</td>
                <% loop $Cells %><td class="num<% if $IsAbnormal %> abn<% end_if %>">$Value</td><% end_loop %>
            </tr>
            <% end_loop %>
        </tbody>
    </table>
    <p class="muted"><%t CareHomeReport.ABN_HINT "Podebljano crveno: vrijednost izvan referentnog opsega." %></p>
    <% else %>
    <p class="muted"><%t CareHomeReport.NO_VITALS "Nema mjerenja u ovom periodu." %></p>
    <% end_if %>
<% end_with %>

<h2><%t CareHomeReport.THERAPY "Terapija" %></h2>
<% with $Therapy %>
    <p><%t CareHomeReport.THERAPY_SUMMARY "Dato: {done} · Preskočeno: {skipped} · Nije evidentirano: {missed}" done=$Done skipped=$Skipped missed=$Missed %></p>
    <% if $HasRows %>
    <table>
        <thead>
            <tr>
                <th><%t CareHomeReport.WHEN "Termin" %></th>
                <th><%t CareHomeReport.MEDICATION "Lijek" %></th>
                <th><%t CareHomeReport.DOSE "Doza / primjena" %></th>
                <th><%t CareHomeReport.STATE "Status" %></th>
                <th><%t CareHomeReport.REMARK "Evidentirao / razlog" %></th>
            </tr>
        </thead>
        <tbody>
            <% loop $Rows %>
            <tr>
                <td>$Task.DueDate.Format('dd.MM.') $Task.DueTime.Format('HH:mm')</td>
                <td><% if $Task.Medication %>$Task.Medication<% else %>$Task.Title<% end_if %></td>
                <td>$Task.Dose<% if $Task.Route %> · $Task.RouteLabel<% end_if %></td>
                <td class="$State">$StateLabel</td>
                <td>
                    <% if $Task.CompletedByID %>$Task.CompletedBy.FirstName $Task.CompletedBy.Surname, $Task.CompletedAt.Format('dd.MM. HH:mm')<% end_if %>
                    <% if $Task.SkipReason %><br><strong>$Task.SkipReason</strong><% end_if %>
                    <% if $Task.ExecutionNote %><br><span class="muted">$Task.ExecutionNote</span><% end_if %>
                </td>
            </tr>
            <% end_loop %>
        </tbody>
    </table>
    <% else %>
    <p class="muted"><%t CareHomeReport.NO_THERAPY "Nema terapije u ovom periodu." %></p>
    <% end_if %>
<% end_with %>

<h2><%t CareHomeReport.NOTES "Napomene" %></h2>
<% if $Notes %>
<table>
    <thead>
        <tr>
            <th style="width: 28mm"><%t CareHomeReport.TIME "Vrijeme" %></th>
            <th style="width: 20mm"><%t CareHomeReport.SEVERITY "Važnost" %></th>
            <th><%t CareHomeReport.TEXT "Napomena" %></th>
            <th style="width: 32mm"><%t CareHomeReport.AUTHOR "Autor" %></th>
        </tr>
    </thead>
    <tbody>
        <% loop $Notes %>
        <tr<% if $isCritical %> class="critical"<% end_if %>>
            <td>$Created.Format('dd.MM.yyyy HH:mm')</td>
            <td<% if $isCritical %> class="abn"<% end_if %>>$SeverityLabel</td>
            <td>$NoteText</td>
            <td>$CreatedBy.FirstName $CreatedBy.Surname</td>
        </tr>
        <% end_loop %>
    </tbody>
</table>
<% else %>
<p class="muted"><%t CareHomeReport.NO_NOTES "Nema napomena u ovom periodu." %></p>
<% end_if %>
