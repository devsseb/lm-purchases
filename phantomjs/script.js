function exit(data)
{
	console.log('exit');
	if (typeof data == 'object')
		data = JSON.stringify(data);
	console.log(data);

	phantom.exit();
}

phantom.onError = function(message, trace)
{
	exit({fatalerror: true, message: message, trace: trace});
}

var system = require('system');
var page = require('webpage').create();
var fs = require('fs'); 

var urlLogin = 'https://sso.leroymerlin.fr/oauth-server/login?client_id=FRLM-FRONTWEB';
var urlLoginSuccess = 'https://sso.leroymerlin.fr/oauth-server/login';
var urlLoginError = 'https://sso.leroymerlin.fr/oauth-server/login?authentication_error=true&client_id=FRLM-FRONTWEB';
var urlTicket = 'https://www.leroymerlin.fr/v3/compteinternaute/espaceperso/tickets/ajax-liste.do?pageId=';

var ticketPage = -1;
var tickets = [];

function loadNextPage()
{
	ticketPage++;
	console.log('Tickets page ' + ticketPage + ' loading...');
	page.open(urlTicket + ticketPage);
}

function loadNextProducts()
{
	for (var i = 0; i < tickets.length; i++) {
		if (typeof tickets[i].products == 'string') {
			console.log('Products ticket ' + tickets[i].number + ' loading...');
			page.open(tickets[i].products);
			return;
		}
	}
	exit(tickets);	
}

page.onLoadFinished = function(status)
{
	console.log(status, page.url);
	switch (page.url) {
		case urlLogin :
			console.log('Login...');
			page.evaluate(function(login, password) {
				document.getElementById('username').value = login;
				document.getElementById('password').value = password;
				document.getElementById('loginForm').submit();
			}, system.args[1], system.args[2]);
			break;
		case urlLoginSuccess :
			console.log('Logged.');

			if (system.args.length > 3 && system.args[3] == 'login')
				exit({login:'success'});
			loadNextPage();
			break;
		case urlLoginError :
			console.log('Login error.');
			exit({login:'error'});

		case urlTicket + ticketPage :

			console.log('Tickets page ' + ticketPage + ' loaded.');
			var ticketsPage = page.evaluate(function(ticketPage) {
				if (document.getElementsByTagName('APM_DO_NOT_TOUCH').length)
					return [];

				var domDates = document.getElementsByClassName('date');
				var domNumbers = document.getElementsByClassName('numero');
				var domLocations = document.getElementsByClassName('magasin');
				var domPrices = document.getElementsByClassName('prix');
				var domLinks = document.getElementsByTagName('a');

				var tickets = [];

				for (var i = 0; i < domDates.length; i++) {

					var date = domDates[i].innerText;
					date = /([0-9]{2}) (.*?) ([0-9]{4})/.exec(date);
					date[2] = {janvier:'01', février:'02', mars:'03', avril:'04', mai:'05', juin:'06', juillet:'07', août:'08', septembre:'09', octobre:'10', novembre:'11', décembre:'12'}[date[2]];
					date = date[3] + date[2] + date[1];

					tickets.push({
						date: date,
						number: domNumbers[i].innerText.replace('N° Ticket: ', ''),
						location: domLocations[i].innerText,
						price: domPrices[i].innerText.replace(' €', ''),
						products: domLinks[i].href.replace('commandes/ticket_', 'tickets/')
					});
				}

				return tickets;

			});

			tickets = tickets.concat(ticketsPage);
			console.log('Tickets page ' + ticketPage + ', ' + ticketsPage.length + ' ticket' + (ticketsPage.length > 1 ? 's' : '') + ' found.');

			if (ticketsPage.length == 0)
				loadNextProducts();
			else
				loadNextPage();

			break;
		default :

			for (var i = 0; i < tickets.length; i++) {

				if (tickets[i].products == page.url) {

					console.log('Products ticket ' + tickets[i].number + ' loaded.');
					var products = page.evaluate(function() {

						var domLibelles = document.getElementsByClassName('libelle');
						var domRefs = document.getElementsByClassName('ref');
						var domImages = document.getElementsByClassName('visu-produit');
						var domTotals = document.getElementsByClassName('command-amount');

						var products = [];

						for (var i = 0; i < domLibelles.length; i++) {
							products.push({
								label: domLibelles[i].innerText,
								ref: domRefs[i].innerText.replace('Réf:', ''),
								image: domImages[i].src,
								total: domTotals[i].innerText.replace(' €', ''),
								quantity: domTotals[i].parentElement.previousElementSibling.innerText,
								price: domTotals[i].parentElement.previousElementSibling.previousElementSibling.innerText.replace(' €', '')
							});
						}

						return products;
					});

					tickets[i].products = products;
					console.log('Products ticket ' + tickets[i].number + ', ' + products.length + ' product' + (products.length > 1 ? 's' : '') + ' found.');

					loadNextProducts();

					break;
				}
			}

			break;
	}
}

page.open(urlLogin);