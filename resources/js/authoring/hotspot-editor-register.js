import { hotspotEditor } from './hotspot-editor';

document.addEventListener('alpine:init', () => {
  window.Alpine.data('hotspotEditor', hotspotEditor);
});
