<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\Utilisateur;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'nom'           => 'Trabelsi',
                'prenom'        => 'Mohamed',
                'email'         => 'admin@gmail.com',
                'password'      => Hash::make('admin123'),
                'matricule'     => 'ADM-001',
                'role'          => 'admin',
                'etablissement' => 'ISIMM',
                'status'        => 'active',
            ],
            [
                'nom'           => 'Ben Salah',
                'prenom'        => 'Khaled',
                'email'         => 'directeur@gmail.com',
                'password'      => Hash::make('dir123'),
                'matricule'     => 'DIR-001',
                'role'          => 'directeur',
                'etablissement' => 'ISIMM',
                'status'        => 'active',
            ],
            [
                'nom'           => 'Chaabane',
                'prenom'        => 'Sonia',
                'email'         => 'chef@gmail.com',
                'password'      => Hash::make('chef123'),
                'matricule'     => 'CHF-001',
                'role'          => 'chef',
                'etablissement' => 'ISIMM',
                'status'        => 'active',
            ],
            [
                'nom'           => 'Gharbi',
                'prenom'        => 'Amine',
                'email'         => 'encadrant@gmail.com',
                'password'      => Hash::make('enc123'),
                'matricule'     => 'ENC-001',
                'role'          => 'encadrant',
                'etablissement' => 'ISIMM',
                'status'        => 'active',
            ],
            [
                'nom'           => 'Hamdi',
                'prenom'        => 'Rim',
                'email'         => 'enseignant@gmail.com',
                'password'      => Hash::make('ens123'),
                'matricule'     => 'ENS-001',
                'role'          => 'enseignant',
                'etablissement' => 'ISIMM',
                'status'        => 'active',
            ],
            [
                'nom'           => 'Boughanmi',
                'prenom'        => 'Yassine',
                'email'         => 'etudiant@gmail.com',
                'password'      => Hash::make('etu123'),
                'matricule'     => 'ETU-001',
                'role'          => 'etudiant',
                'etablissement' => 'ISIMM',
                'status'        => 'active',
            ],
        ];

        foreach ($users as $user) {
            Utilisateur::firstOrCreate(['email' => $user['email']], $user);
        }
    }
}