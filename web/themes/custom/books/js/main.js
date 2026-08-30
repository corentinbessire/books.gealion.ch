import '../css/tailwind.css';
import Alpine from 'alpinejs';
import confirmAction from './components/confirm-action.js';
import isbnScanner from './components/isbn-scanner.js';

window.Alpine = Alpine;

Alpine.data('isbnScanner', isbnScanner);
Alpine.data('confirmAction', confirmAction);

Alpine.start();
