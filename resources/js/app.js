import './bootstrap';

import Alpine from 'alpinejs';

import { lessonPlayer } from './lesson-player/player';

Alpine.data('lessonPlayer', lessonPlayer);

window.Alpine = Alpine;

Alpine.start();
