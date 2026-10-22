<table width="100%" style="font-family: dejavusans, sans-serif; font-size: 8pt; border-bottom: 0.4mm solid #155e63; border-collapse: collapse;">
    <tr>
        <td style="border: 0; padding: 0 0 1.5mm;">
            <strong style="font-size: 10pt; color: #155e63;">$FacilityName</strong>
            <% if $FacilityAddress %><br>$FacilityAddress<% end_if %>
        </td>
        <td style="border: 0; padding: 0 0 1.5mm; text-align: right;">
            <strong>$Patient.FirstName $Patient.LastName</strong><br>
            <%t CareHomeReport.PERIOD "Period" %>: $From.Format('dd.MM.yyyy') – $To.Format('dd.MM.yyyy')
        </td>
    </tr>
</table>
