<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    public function run()
    {
        $clients = [
            'Acme Corp',
            'Globex Inc',
            'Initech',
            'Umbrella Corp',
            'Wayne Enterprises',
            'Tech Solutions Ltd',
            'Digital Innovations Inc',
            'Creative Studios',
            'Enterprise Systems',
            'Modern Web Solutions',
        ];

        foreach ($clients as $name) {
            Client::create(['name' => $name]);
        }
    }
}
