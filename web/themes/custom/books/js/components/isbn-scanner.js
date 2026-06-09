import { BrowserMultiFormatReader } from '@zxing/browser';
import { BarcodeFormat, DecodeHintType } from '@zxing/library';

/**
 * Alpine component: scan a book barcode (EAN-13) and fill the ISBN field.
 *
 * A book's back-cover barcode is an EAN-13 whose digits ARE its ISBN-13, so we
 * only need to decode the number and write it into the existing ISBN input.
 *
 * iOS browsers (incl. Brave/Chrome) run on WebKit, which has no native
 * BarcodeDetector API, so decoding is done in pure JS by ZXing.
 *
 * Expects two refs in the same Alpine scope:
 * - $refs.isbn:  the ISBN <input> to fill
 * - $refs.video: the <video> element used as the camera preview
 */
export default function isbnScanner() {
  return {
    open: false,
    starting: false,
    error: '',
    controls: null,

    /**
     * Open the overlay and start the camera + decode loop.
     */
    async start() {
      this.error = '';
      this.starting = true;
      this.open = true;

      // Let the overlay (and its <video> ref) render before we touch it.
      await this.$nextTick();

      const video = this.$refs.video;
      if (!video) {
        this.starting = false;
        this.error = this.errorMessage('generic');
        return;
      }

      const hints = new Map();
      hints.set(DecodeHintType.POSSIBLE_FORMATS, [BarcodeFormat.EAN_13]);
      const reader = new BrowserMultiFormatReader(hints);

      try {
        this.controls = await reader.decodeFromConstraints(
          { video: { facingMode: 'environment' } },
          video,
          (result, _error, controls) => {
            // Keep a handle so stop() can release the camera at any time.
            if (!this.controls) {
              this.controls = controls;
            }
            if (!result) {
              return;
            }
            const code = result.getText();
            // Books use the Bookland EAN prefix 978/979. Ignore anything else
            // (price add-ons, non-book products) and keep scanning.
            if (!/^97[89]/.test(code)) {
              return;
            }
            this.onResult(code);
          },
        );
      } catch (error) {
        this.handleError(error);
      } finally {
        this.starting = false;
      }
    },

    /**
     * Write the scanned ISBN into the field and close the scanner.
     */
    onResult(code) {
      const input = this.$refs.isbn;
      if (input) {
        input.value = code;
        // Notify Drupal/Alpine listeners that the value changed.
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
      }
      this.stop();
    },

    /**
     * Stop the decode loop, release the camera, and close the overlay.
     */
    stop() {
      if (this.controls) {
        this.controls.stop();
        this.controls = null;
      }
      this.open = false;
    },

    /**
     * Map a getUserMedia error to a friendly message; manual entry still works.
     */
    handleError(error) {
      this.starting = false;
      if (error && error.name === 'NotAllowedError') {
        this.error = this.errorMessage('denied');
      } else if (error && error.name === 'NotFoundError') {
        this.error = this.errorMessage('notfound');
      } else {
        this.error = this.errorMessage('generic');
      }
    },

    errorMessage(kind) {
      const messages = {
        denied:
          'Camera access was denied. You can still type the ISBN manually.',
        notfound: 'No camera was found. You can still type the ISBN manually.',
        generic:
          'Could not start the camera. You can still type the ISBN manually.',
      };
      return messages[kind] || messages.generic;
    },
  };
}
