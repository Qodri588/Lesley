#!/bin/bash

# Menjalankan fld.php
php fld.php

# Memeriksa status eksekusi fld.php
if [ $? -eq 0 ]; then
    echo "fld.php berhasil dijalankan. Menjalankan dood.php..."
    # Menjalankan dood.php
    php dood.php
    if [ $? -eq 0 ]; then
        echo "dood.php berhasil dijalankan."
    else
        echo "Terjadi kesalahan saat menjalankan dood.php."
    fi
else
    echo "Terjadi kesalahan saat menjalankan fld.php. dood.php tidak dijalankan."
fi