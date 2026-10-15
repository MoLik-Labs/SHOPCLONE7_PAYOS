/* wrong: the player preloads audio and builds it with new Audio() */
var player = new Audio('https://media.example/a.mp3');
player.preload = 'auto';
