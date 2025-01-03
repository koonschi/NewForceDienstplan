
let userData = {};

// short querySelector
function _(s) {
    return document.querySelector(s);
}

function refresh() {
    // declare and fill event data
    userData = {};

    requestUsers(function (data) {
        userData = {};
        for (let i in data) {
            let user = data[i];
            userData[user.id] = user;
        }

        _("#mainpage").innerHTML = buildIndexHtml();
    });

}

refresh();

function buildIndexHtml()
{
    let html = "";

    html += buildNavHtml();

    html += '<h1>Dev Maintenance</h1>';
    html += '<h2>Testmail</h2>';
    html += '<p>Verschickt eine Test-Mail an den Admin-Account</p>';
    html += '<form method="POST" action="">';
    html += '    <input type="submit" name="send_testmail" value="Test-Email verschicken"></input>';
    html += '</form>';

    html += '<h2>Update DB</h2>';
    html += '<form method="POST" action="">';
    html += '    <input type="submit" name="update_db" value="Update Database Schema"></input>';
    html += '</form>';

    html += '<h2>Auf Werkseinstellungen Zurücksetzen</h2>';
    html += '<form method="POST" action="">';
    html += '    <input type="submit" name="reinit_everything" value="Auf Werkseinstellungen zurücksetzen"></input>';
    html += '</form>';

    return html;
}
