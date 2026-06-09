import '../css/tailwind.css';
import Alpine from 'alpinejs';
import isbnScanner from './components/isbn-scanner.js';

window.Alpine = Alpine;

Alpine.data('isbnScanner', isbnScanner);

Alpine.start();
