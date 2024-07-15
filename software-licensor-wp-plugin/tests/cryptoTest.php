<?php
use PHPUnit\Framework\TestCase;

class CryptographyTests extends TestCase
{
    public function testPrintVersionInfo()
    {
        print('PHP Version: ' . phpversion() . "\n");
        print('OpenSSL version: ' . OPENSSL_VERSION_TEXT . "\n");
        $this->assertTrue(true);
    }
    public function testEstablishPrivateKey()
    {
        $config = [
            "private_key_type" => OPENSSL_KEYTYPE_EC,
            "curve_name" => "secp384r1"
        ];
        $private_key = openssl_pkey_new($config);
        openssl_pkey_export($private_key, $pem);
        print($pem . "\n");
        $pubkey = openssl_pkey_get_details($private_key)['key'];
        print('pubkey: ' . $pubkey . "\n");
        $this->assertTrue(true);
    }
    public function testKeyDerivation()
    {
        $private_key_pem = '-----BEGIN EC PRIVATE KEY-----
MIGkAgEBBDDGcz2Tjp81Njz5yVdCWz+5cmRSP8yG67mUR4J8JdRJ3wWkrH2NaH5A
idnam1TGPaygBwYFK4EEACKhZANiAATRLw6yqiOinG+ePYBnvDc44fPsW1uKCdex
d0Pe94iJZT+HJNdWeuJ+94mFbhN63tbt/OoQ03ejYmS+umbwwPkgkUx56f5EAt2a
7TeeBXrNRGNYgrYfHFmvL/NzZ/d5mEE=
-----END EC PRIVATE KEY-----';
        $different_pubkey_pem = '-----BEGIN PUBLIC KEY-----
MHYwEAYHKoZIzj0CAQYFK4EEACIDYgAE6+NQeVIV6EZNYUaK8tpkD/LdEzBpQXI9
gLlsuUUAbJ8/ejQzAHAaNtdJwLkdhm4NSY764Wumxsh4wmt/3dkjmhihZjSbCe4t
w86Ooe6JKEL5XOR31/6+yK3r+r8pKcuP
-----END PUBLIC KEY-----';

        $shared_secret = openssl_pkey_derive($different_pubkey_pem, $private_key_pem);

        print("\nShared secret\n" . base64_encode($shared_secret) . "\n");

        $this->assertEquals($shared_secret, base64_decode('lPRTaMzkt8cbcUm2NKGSufqFzKq/go0WIcfOgoG0wjQOzI1YXDH4+5aMYAo+tJjR'));

        $info = 'Software Licensor API Authentication v2';
        $salt = '123456789012345678901234567890123456789012345678';

        $symmetric_key = hash_hkdf('sha384', $shared_secret, 32, $info, $salt);

        print("\nKey: \n" . base64_encode($symmetric_key) . "\n");

        $this->assertEquals($symmetric_key, base64_decode('PCthzS+lDm+3PfMQWJOW76yPM/sB3X2nKgbaLtSiEG0='));
    }
}
?>