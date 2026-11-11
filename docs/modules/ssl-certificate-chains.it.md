# Catene dei certificati SSL

I fornitori restituiscono la catena degli intermedi in molte forme: con un certificato mancante, in ordine sbagliato, con la root inclusa, oppure per niente. PNLCS la ricostruisce per tutti i moduli SSL, così quello che scarichi o incolli si installa correttamente.

## Come viene ricostruita

Partendo dal certificato del dominio, PNLCS cerca ogni emittente tra i certificati inviati dal fornitore. Se ne manca uno, lo scarica dall'indirizzo **CA Issuers** scritto nel certificato (PEM, DER o PKCS#7; i download vengono conservati per una settimana). Si ferma alla root autofirmata, che viene esclusa, come si aspettano i web server. Ogni passaggio è verificato tramite la firma.

La ricostruzione avviene quando un certificato viene aggiornato, importato, scaricato, o quando se ne apre la pagina. Se non si trova un percorso completo fino a una root, i dati del fornitore restano invariati e l'evento viene registrato nel log.

Perché gli intermedi mancanti vengano recuperati, il server su cui gira PNLCS deve poter raggiungere gli indirizzi `http` delle autorità di certificazione.

## Installare un certificato

La pagina del certificato, per lo staff e per il cliente, ha un riquadro **Installa sul server** con tre campi, nell'ordine in cui li chiede un pannello di controllo, ciascuno con il pulsante **Copia**:

1. **Chiave privata (\*.key)**
2. **Certificato (\*.crt)**
3. **Certificato CA (\*-ca.crt)**

In Plesk vanno incollati in **SSL/TLS Certificates → Upload the certificate as text** (i nomi possono cambiare con la lingua del pannello).

- Se il CSR è stato generato fuori da PNLCS, il pannello non ha la chiave privata e il campo non compare. La chiave resta dove è stato creato il CSR: in Plesk, apri la richiesta di certificato esistente e incolla solo il certificato e il certificato CA.
- Lo staff senza il permesso `manage_services` non vede la chiave privata.

Lo ZIP di download contiene gli stessi dati come `.crt`, `.ca-bundle`, `.fullchain.crt` e, se il pannello la possiede, `.key`. Nei nomi dei file dei certificati wildcard compare `wildcard.example.com`, perché Windows non accetta `*`.

## Controllare un sito

```
echo | openssl s_client -connect www.example.com:443 -servername www.example.com 2>/dev/null | grep "Verify return"
```

`Verify return code: 0 (ok)` significa che il server invia una catena completa. Il codice 21, `unable to verify the first certificate`, significa che il certificato CA non è stato installato.
