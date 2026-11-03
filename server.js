const express = require('express');
const app = express();
const path = require('path');

// Serves your HTML file
app.get('/', (req, res) => {
    res.sendFile(path.join(__dirname, 'index.html')); // Make sure your HTML file is named index.html!
});

const PORT = process.env.PORT || 10000;
app.listen(PORT, () => {
    console.log(`Server is running on port ${PORT}`);
});
