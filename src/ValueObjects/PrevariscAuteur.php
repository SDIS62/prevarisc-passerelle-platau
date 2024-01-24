<?php

namespace App\ValueObjects;

class PrevariscAuteur
{
    private ?string $prenom;
    private ?string $nom;
    private ?string $email;
    private ?string $telephone;

    public function __construct(?string $prenom, ?string $nom, ?string $email, ?string $telephone_fixe, ?string $telephone_portable)
    {
        $this->prenom    = $prenom;
        $this->nom       = $nom;
        $this->email     = $email;
        $this->telephone = '' !== $telephone_fixe ? $telephone_fixe : $telephone_portable;
    }

    public function prenom() : ?string
    {
        return $this->prenom;
    }

    public function nom() : ?string
    {
        return $this->nom;
    }

    public function email() : ?string
    {
        return $this->email;
    }

    public function telephone() : ?string
    {
        return $this->telephone;
    }
}
