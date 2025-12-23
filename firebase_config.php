&lt;?php

// Firebase configuration for your project.
// In production, consider moving these to environment variables instead.

define('FIREBASE_DATABASE_URL', 'https://union-1-1b7ae-default-rtdb.asia-southeast1.firebasedatabase.app');
define('FIREBASE_WEB_API_KEY', 'AIzaSyCf_wsaknrx5Z6TuezoRi-6AxLX4UJKDyU');

// Assumed Realtime Database structure:
//
// {
//   "devices": {
//     "DEVICE_ID_1": {
//       "name": "Device name",
//       "phoneNumber": "+15550001111",
//       "credits": 10,
//       "smsAllowed": true,
//       "lastSeen": 1717670400000,
//       "messages": {
//          "MSG_ID_1": { "body": "...", "timestamp": 1717670400000, "direction": "out" },
//          ...
//       }
//     },
//     ...
//   }
// }