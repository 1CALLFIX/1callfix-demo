/**
 * Events E2: draws each ticket's QR code on the tickets page (`<div data-qr="T...">`). The library is loaded only on
 * pages that have a ticket (its own small chunk), never on the homepage's critical path.
 */
function drawQrCodes() {
    const boxes = document.querySelectorAll('[data-qr]:not([data-qr-drawn])');
    if (boxes.length === 0) {
        return;
    }
    import('qrcode-generator').then(({ default: qrcode }) => {
        boxes.forEach((box) => {
            const qr = qrcode(0, 'M');
            qr.addData(box.getAttribute('data-qr'));
            qr.make();
            box.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
            box.setAttribute('data-qr-drawn', '1');
        });
    });
}

document.addEventListener('DOMContentLoaded', drawQrCodes);
document.addEventListener('livewire:navigated', drawQrCodes);
document.addEventListener('livewire:init', () => {
    window.Livewire?.hook?.('morph.updated', () => drawQrCodes());
});
