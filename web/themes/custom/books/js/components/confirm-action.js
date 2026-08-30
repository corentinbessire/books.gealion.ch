/**
 * Alpine component: ask before following a link that changes data.
 *
 * The reading-log transitions are one-click and stamp a date, so the button
 * swaps itself for a Yes/Cancel pair rather than acting immediately.
 *
 * Usage:
 *   <div x-data="confirmAction('/activity/12/finish')"> ... </div>
 *
 * @param {string} href Destination to visit once confirmed.
 * @returns {object} Alpine component state.
 */
export default (href) => ({
  href,
  confirming: false,

  ask() {
    this.confirming = true;
  },

  cancel() {
    this.confirming = false;
  },

  go() {
    window.location.href = this.href;
  },
});
