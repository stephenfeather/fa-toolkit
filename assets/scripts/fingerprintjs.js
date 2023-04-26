
console.log("Initializing Fingerprint");
const fpPromise = import('https://fpcdn.io/v3/Oo4CqqyVw0pCzwTpD4Mx')
    .then(FingerprintJS => FingerprintJS.load({
        apiKey: 'Oo4CqqyVw0pCzwTpD4Mx',
        endpoint: 'https://metrics.featherarms.com'
    }));

fpPromise
    .then(fp => fp.get(
        {
            tag: {
                PHPSESSID: sessionid,
                userID: userid
            }
        }
    ))
    .then(result => console.log(result));
