// VibeCode wraps the application; BX24.init is not used in this runtime.
let lastHeight = 0;
function resizeFrame() {
  const height = Math.ceil(document.querySelector('main').getBoundingClientRect().bottom + window.scrollY + 24);
  if (window.parent !== window && height !== lastHeight) {
    lastHeight = height;
    // Only geometry is sent. Enable placementResizeEnabled in VibeCode before portal acceptance.
    window.parent.postMessage({ type: 'vibe:resize', height }, '*');
  }
}
new ResizeObserver(resizeFrame).observe(document.querySelector('main'));
window.addEventListener('load', resizeFrame);
