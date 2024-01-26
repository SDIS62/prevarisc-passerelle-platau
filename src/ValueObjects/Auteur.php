<?php

namespace App\ValueObjects;

class Auteur
{
    private ?string $prenomAuteur;
    private ?string $nomAuteur;
    private ?string $emailAuteur;
    private ?string $telephoneAuteur;

    public function __construct(?string $prenom, ?string $nom, ?string $email, ?string $telephone_fixe, ?string $telephone_portable)
    {
        $this->prenomAuteur    = $prenom;
        $this->nomAuteur       = $nom;
        $this->emailAuteur     = $email;
        $this->telephoneAuteur = '' !== $telephone_fixe ? $telephone_fixe : $telephone_portable;
    }

    public function prenom() : ?string
    {
        return $this->prenomAuteur;
    }

    public function nom() : ?string
    {
        return $this->nomAuteur;
    }

    public function email() : ?string
    {
        return $this->emailAuteur;
    }

    public function telephone() : ?string
    {
        return $this->telephoneAuteur;
    }
}
