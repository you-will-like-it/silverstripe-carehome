import './portal.css';
import Alpine from 'alpinejs';

/**
 * PIN keypad. The visible input is readonly so phones don't pop up their keyboard;
 * hardware keyboards still work through the keydown handler.
 */
Alpine.data('pinPad', (min = 4, max = 6) => ({
  pin: '',
  min,
  max,
  press(digit) {
    if (this.pin.length < this.max) {
      this.pin += String(digit);
    }
  },
  back() {
    this.pin = this.pin.slice(0, -1);
  },
  clear() {
    this.pin = '';
  },
  get ready() {
    return this.pin.length >= this.min;
  },
  onKey(event) {
    if (/^\d$/.test(event.key)) {
      this.press(event.key);
      event.preventDefault();
    } else if (event.key === 'Backspace') {
      this.back();
      event.preventDefault();
    } else if (event.key === 'Enter' && this.ready) {
      this.$refs.form.requestSubmit();
      event.preventDefault();
    }
  },
}));

/**
 * Camera QR scanner. html5-qrcode is loaded on demand (separate chunk).
 * Only codes pointing to /portal/bed/<hash> are accepted; navigation stays on this origin.
 */
Alpine.data('qrScanner', () => ({
  open: false,
  error: '',
  scanner: null,
  async start() {
    this.open = true;
    this.error = '';
    await this.$nextTick();
    try {
      const { Html5Qrcode } = await import('html5-qrcode');
      this.scanner = new Html5Qrcode('carehome-qr-reader', { verbose: false });
      await this.scanner.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: { width: 240, height: 240 }, aspectRatio: 1 },
        (text) => this.onScan(text),
        () => {},
      );
    } catch (e) {
      this.error = this.$root.dataset.errCamera || 'Camera error';
    }
  },
  async stop() {
    if (this.scanner) {
      try {
        if (this.scanner.isScanning) {
          await this.scanner.stop();
        }
        this.scanner.clear();
      } catch (e) {
        // ignore: camera already released
      }
      this.scanner = null;
    }
    this.open = false;
  },
  onScan(text) {
    const match = String(text).match(/\/portal\/bed\/([a-f0-9]{16,64})(?:[/?#]|$)/i);
    if (!match) {
      this.error = this.$root.dataset.errCode || 'Unknown code';
      return;
    }
    const target = `${this.$root.dataset.base.replace(/\/$/, '')}/bed/${match[1].toLowerCase()}`;
    this.stop().then(() => {
      window.location.assign(target);
    });
  },
}));

// Prevent double submits on slow Wi-Fi (buttons disabled right after the submit is dispatched).
document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || event.defaultPrevented) {
    return;
  }
  window.setTimeout(() => {
    form.querySelectorAll('button[type="submit"]').forEach((button) => {
      button.disabled = true;
      button.classList.add('opacity-60');
    });
  }, 0);
});

// Restore buttons when the page comes back from bfcache (back button).
window.addEventListener('pageshow', (event) => {
  if (event.persisted) {
    document.querySelectorAll('button[type="submit"]').forEach((button) => {
      button.disabled = false;
      button.classList.remove('opacity-60');
    });
  }
});

window.Alpine = Alpine;
Alpine.start();
