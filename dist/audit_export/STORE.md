Publishing to the Nextcloud App Store is mostly a one-time signing and registration setup. After that, each release is just a link plus a signature.

1. Prepare the app
Give it a unique app ID (the folder name and the <id> in appinfo/info.xml) with complete metadata. Then package it as a .tar.gz and host it publicly, typically as a GitHub release asset. The store doesn’t host the archive itself, so it can’t guarantee the contents weren’t tampered with or that the real developer uploaded them. That’s why it requires you to sign your app with a certificate. 
readthedocs

2. Get a certificate (once per app)
Generate a private key and a CSR in ~/.nextcloud/certificates/ with openssl req -nodes -newkey rsa:4096 -keyout APP_ID.key -out APP_ID.csr -subj "/CN=APP_ID". Submit the contents of APP_ID.csr as a pull request to the github.com/nextcloud/app-certificate-requests repository. Once Nextcloud approves it, they post your signed public certificate (APP_ID.crt) in your app’s directory there. Save it in the same folder with that file name. This step involves a human review, so expect some waiting. 
App Developer Guide¶
 +2

3. Register the app ID (once)
Use the register app web interface at apps.nextcloud.com/developer/apps/new, or the REST API. You paste in the certificate and a signature over your app ID: echo -n "APP_ID" | openssl dgst -sha512 -sign ~/.nextcloud/certificates/APP_ID.key | openssl base64. Nextcloud verifies both and registers you as the app’s owner, and from then on you can publish releases. 
etos-pro
readthedocs

4. Publish each release
Sign the archive with openssl dgst -sha512 -sign ~/.nextcloud/certificates/APP_ID.key /path/to/app.tar.gz | openssl base64. Then submit the download URL and the signature through the upload release form or the API. Sign the exact bytes you host. The store checks the signature against the file it downloads, not against your local build. 
readthedocs
github

Protect the private key. If the key is lost or leaked, you have to re-request a certificate and re-register, and that deletes all existing releases. Store the .key in Vaultwarden under infra, plus an encrypted backup. It should never go in a repo, a Nextcloud file, or HTD Memory. The store account’s API token belongs in Vaultwarden as well. 
readthedocs
readthedocs

If the app is only for us or a client, you may not need the store at all. You can drop the app into the instance’s custom_apps/ folder and enable it with occ app:enable, with no certificate process. The store makes sense when we want public distribution or visibility as a marketing signal.