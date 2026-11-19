/* QRRS report export: Excel (SheetJS) and PDF (jsPDF + autotable), libraries loaded when needed */
(function () {
    'use strict';
    function loadScript(src, done) {
        if (document.querySelector('script[src="' + src + '"]')) { done(); return; }
        var s = document.createElement('script');
        s.src = src;
        s.onload = done;
        s.onerror = function () { alert('Could not load the export library. Please check the internet connection.'); };
        document.head.appendChild(s);
    }
    window.qrrsExportExcel = function (tableId, filename) {
        var t = document.getElementById(tableId);
        if (!t) { alert('Nothing to export.'); return; }
        loadScript('https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js', function () {
            var wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, XLSX.utils.table_to_sheet(t), 'Report');
            XLSX.writeFile(wb, filename + '-' + new Date().toISOString().slice(0, 10) + '.xlsx');
        });
    };
    window.qrrsExportPDF = function (tableId, title, period, filename) {
        var t = document.getElementById(tableId);
        if (!t) { alert('Nothing to export.'); return; }
        loadScript('https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js', function () {
            loadScript('https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js', function () {
                var jsPDF = window.jspdf.jsPDF;
                var doc = new jsPDF({ orientation: 'portrait' });
                var w = doc.internal.pageSize.getWidth();
                doc.setFontSize(14); doc.setFont(undefined, 'bold'); doc.text(title, 14, 14);
                doc.setFontSize(9); doc.setFont(undefined, 'normal');
                doc.text(period, 14, 20);
                doc.text('Generated: ' + new Date().toLocaleDateString(), w - 14, 20, { align: 'right' });
                doc.autoTable({ html: t, startY: 26, styles: { fontSize: 9, cellPadding: 3 }, headStyles: { fillColor: [30, 41, 59] } });
                doc.save(filename + '-' + new Date().toISOString().slice(0, 10) + '.pdf');
            });
        });
    };
})();