/*
 * Circles - Bring cloud-users closer together.
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@pontapreta.net>
 * @copyright 2017
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

/** global: OC */
/** global: OCA */

$(document).ready(function () {

	/**
	 * @constructs Navigation
	 */
	var Navigation = function () {
		this.initialize();
	};

	Navigation.prototype = {

		initialize: function () {
			var self = this;
			var api = OCA.Circles.api;

			var currCirclesType = '';
			var currentCircle = 0;
			var currentCircleLevel = 0;
			var lastSearchCircle = '';
			var lastSearchUser = '';


			$('#circles_new_type_definition div').fadeOut(0);
			$('#circles_new_type_' + ($('#circles_new_type option:selected').val())).fadeIn(0);

			$('#circles_new_type').hide();
			$('#circles_new_submit').hide();
			$('#circles_new_type_definition').hide();

			$('#circles_new_name').on('keyup', function () {
				currentCircle = 0;
				currentCircleLevel = 0;

				$('#app-navigation.circles').hide('slide', 800);
				$('#circles_list div').removeClass('selected');
				$('#emptycontent').show(800);
				$('#mainui').fadeOut(800);

				if ($('#circles_new_name').val() !== '') {
					$('#circles_new_type').fadeIn(300);
					$('#circles_new_submit').fadeIn(500);
					$('#circles_new_type_definition').fadeIn(700);
				}
				else {
					$('#circles_new_type').fadeOut(700);
					$('#circles_new_submit').fadeOut(500);
					$('#circles_new_type_definition').fadeOut(300);
				}
			});

			$('#circles_new_type').on('change', function () {

				currentCircle = 0;
				currentCircleLevel = 0;

				$('#app-navigation.circles').hide('slide', 800);
				$('#circles_list div').removeClass('selected');
				$('#emptycontent').show(800);
				$('#mainui').fadeOut(800);

				$('#circles_new_type_definition div').fadeOut(300);
				$('#circles_new_type_' + ($('#circles_new_type option:selected').val())).fadeIn(
					300);
			});

			$('#circles_new_submit').on('click', function () {
				api.createCircle($('#circles_new_type').val(), $('#circles_new_name').val(),
					self.createCircleResult);
			});

			$('#circles_list div').on('click', function () {
				self.displayCirclesList($(this).attr('circle-type'));
			});

			$('#circles_search').on('input propertychange paste focus', function () {
				if (lastSearchCircle == $(this).val().trim()) {
					return;
				}

				lastSearchCircle = $(this).val().trim();
				api.searchCircles(currCirclesType, $(this).val().trim(),
					self.listCirclesResult);
			});

			$('.icon-circles').css('background-image',
				'url(' + OC.imagePath('circles', 'colored') + ')');

			$('#joincircle').on('click', function () {
				api.joinCircle(currentCircle, self.joinCircleResult);
			});

			$('#leavecircle').on('click', function () {
				api.leaveCircle(currentCircle, self.leaveCircleResult);
			});

			$('#joincircle_acceptinvit').on('click', function () {
				api.joinCircle(currentCircle, self.joinCircleResult);
			});

			$('#joincircle_rejectinvit').on('click', function () {
				api.leaveCircle(currentCircle, self.leaveCircleResult);
			});

			$('#addmember').on('input propertychange paste focus', function () {

				if (lastSearchUser == $(this).val().trim()) {
					return;
				}

				lastSearchUser = $(this).val().trim();

				$.get(OC.linkToOCS('apps/files_sharing/api/v1') + 'sharees',
					{
						format: 'json',
						search: $(this).val().trim(),
						perPage: 200,
						itemType: 'principals'
					}, self.searchMembersResult);
			}).blur(function () {
				$('#members_search_result').fadeOut(400);
			});

			$('#members_search_result').hide();


			this.createCircleResult = function (result) {
				var str = 'Circle';
				switch (result.type) {
					case '1':
						str = 'Personal circle';
						break;
					case '2':
						str = 'Hidden circle';
						break;
					case '4':
						str = 'Private circle';
						break;
					case '8':
						str = 'Public circle';
						break;
					default:
						break;
				}

				if (result.status == 1) {
					OCA.notification.onSuccess(str + " '" + result.name + "' created");
					self.displayCirclesList(result.circle.type);
					self.selectCircle(result.circle.id);
				}
				else
					OCA.notification.onFail(
						str + " '" + result.name + "' NOT created: " +
						((result.error) ? result.error : 'no error message'));
			};


			//
			//
			// Circles List
			this.displayCirclesList = function (type) {

				self.currCirclesType = type;
				self.lastSearchCircle = '';
				self.lastSearchUser = '';

				self.currentCircle = 0;
				self.currentCircleLevel = 0;

				$('#app-navigation.circles').show('slide', 800);
				$('#emptycontent').show(800);
				$('#mainui').fadeOut(800);

				$('#circles_search').val('');
				$('#addmember').val('');

				$('#app-navigation.circles').addClass('selected');
				$('#circles_list div').removeClass('selected');

				$('#circles_list').children().each(function () {
					if ($(this).attr('circle-type') == type.toLowerCase()) {
						$(this).addClass('selected');
					}
				});

				$('#app-navigation.circles').children().each(function () {
					if ($(this).attr('id') != 'circles_search') {
						$(this).remove();
					}
				});
				api.listCircles(type, self.listCirclesResult);
			};


			this.listCirclesResult = function (result) {

				if (result.status < 1) {
					OCA.notification.onFail(
						'Issue while retreiving the list of the Circles: ' +
						((result.error) ? result.error : 'no error message'));
					return;
				}

				$('#app-navigation.circles').children().each(function () {
					if ($(this).attr('id') != 'circles_search') {
						$(this).remove();
					}
				});

				var data = result.data;
				for (var i = 0; i < data.length; i++) {

					//	var curr = self.getCurrentCircleTemplate(data[i].id);

					var tmpl = $('#tmpl_circle').html();

					tmpl = tmpl.replace(/%title%/, data[i].name);
					tmpl = tmpl.replace(/%type%/, data[i].type);
					tmpl = tmpl.replace(/%owner%/, data[i].owner.userid);
					tmpl = tmpl.replace(/%status%/, data[i].user.status);
					tmpl = tmpl.replace(/%level_string%/, data[i].user.level_string);
					tmpl = tmpl.replace(/%count%/, data[i].count);
					tmpl = tmpl.replace(/%creation%/, data[i].creation);

					//	if (curr == null) {
					$('#app-navigation.circles').append(
						'<div class="circle" circle-id="' + data[i].id + '">' + tmpl + '</div>');
					//	} else {
					//		$(curr).html(tmpl);
					//	}
				}

				$('#app-navigation.circles').children('.circle').on('click', function () {
					self.selectCircle($(this).attr('circle-id'));
				});
			};


			this.selectCircle = function (circleid) {
				self.lastSearchUser = '';
				$('#addmember').val('');

				api.detailsCircle(circleid, this.selectCircleResult);
			};


			this.selectCircleResult = function (result) {

				$('#mainui #memberslist .table').children('tr').each(function () {
					if ($(this).attr('class') != 'header') {
						$(this).remove();
					}
				});

				if (result.status < 1) {
					OCA.notification.onFail(
						'Issue while retreiving the details of a circle: ' +
						((result.error) ? result.error : 'no error message'));
					return;
				}

				$('#app-navigation.circles').children('.circle').each(function () {
					if ($(this).attr('circle-id') == result.circle_id) {
						$(this).addClass('selected');
					} else {
						$(this).removeClass('selected');
					}
				});
				$('#emptycontent').hide(800);
				$('#mainui').fadeIn(800);
				self.currentCircle = result.circle_id;
				self.currentCircleLevel = result.details.user.level;

				if (result.details.user.level < 6) {
					$('#addmember').hide();
				} else {
					$('#addmember').show();
				}

				$('#joincircle_acceptinvit').hide();
				$('#joincircle_rejectinvit').hide();
				$('#joincircle_request').hide();
				$('#joincircle_invit').hide();

				if (result.details.user.level == 9) {
					$('#joincircle').hide();
					$('#leavecircle').hide();
				}
				else if (result.details.user.level >= 1) {
					$('#joincircle').hide();
					$('#leavecircle').show();
				} else {
					if (result.details.user.status == 'Invited') {
						$('#joincircle_invit').show();
						$('#joincircle_acceptinvit').show();
						$('#joincircle_rejectinvit').show();
						$('#joincircle').hide();
						$('#leavecircle').hide();
					}
					else if (result.details.user.status == 'Requesting') {
						$('#joincircle_request').show();
						$('#joincircle').hide();
						$('#leavecircle').show();
					}
					else {
						$('#joincircle').show();
						$('#leavecircle').hide();
					}
				}

				self.displayMembers(result.details.members);
			};


			this.searchMembersResult = function (response) {

				if (response == null ||
					(response.ocs.data.users === 0 && response.ocs.data.exact.users === 0)) {
					$('#members_search_result').fadeOut(300);
				}
				else {
					var currSearch = $('#addmember').val().trim();
					$('#members_search_result').children().remove();

					$.each(response.ocs.data.exact.users, function (index, value) {
						$('#members_search_result').append(
							'<div class="members_search exact" searchresult="' +
							value.value.shareWith + '">' + value.label + '   (' +
							value.value.shareWith + ')</div>');
					});

					$.each(response.ocs.data.users, function (index, value) {
						var line = value.label + '   (' + value.value.shareWith + ')';
						if (currSearch.length > 0) {
							line =
								line.replace(new RegExp('(' + currSearch + ')', 'gi'), '<b>$1</b>');
						}

						$('#members_search_result').append(
							'<div class="members_search" searchresult="' + value.value.shareWith +
							'">' + line + '</div>');
					});

					$('#members_search_result').children().first().css('border-top-width', '0px');

					$('.members_search').on('click', function () {
						api.addMember(self.currentCircle, $(this).attr('searchresult'),
							self.addMemberResult);
					});
					$('#members_search_result').fadeIn(300);
				}

			};


			this.addMemberResult = function (result) {

				if (result.status == 1) {
					OCA.notification.onSuccess(
						"Member '" + result.name + "' successfully added to the circle");

					self.displayMembers(result.members);
				}
				else {
					OCA.notification.onFail(
						"Member '" + result.name + "' NOT added to the circle: " +
						((result.error) ? result.error : 'no error message'));
				}
			};


			this.displayMembers = function (members) {

				$('#mainui #memberslist .table').children('tr').each(function () {
					if ($(this).attr('class') != 'header')
						$(this).remove();
				});

				if (members === null) {
					$('#mainui #memberslist .table').hide(200);
					return;
				}

				$('#mainui #memberslist .table').show(200);
				for (var i = 0; i < members.length; i++) {

					var tmpl = $('#tmpl_member').html();

					tmpl = tmpl.replace(/%username%/g, members[i].userid);
					tmpl = tmpl.replace(/%level%/g, members[i].level);
					tmpl = tmpl.replace(/%levelstring%/g, members[i].level_string);
					tmpl = tmpl.replace(/%status%/, members[i].status);
					tmpl = tmpl.replace(/%joined%/, members[i].joined);
					tmpl = tmpl.replace(/%note%/,
						((members[i].note) ? members[i].note : ''));

					$('#mainui #memberslist .table').append(tmpl);
				}

				$('#mainui #memberslist .table').children().each(function () {
					if ($(this).attr('member-level') == '9' || self.currentCircleLevel < 6) {
						$(this).children('.delete').hide(0);
					}
				});

				$('#mainui #memberslist .table .delete').on('click', function () {
					var member = $(this).parent().attr('member-id');
					api.removeMember(self.currentCircle, member, self.removeMemberResult);
				});
			};


			this.removeMemberResult = function (result) {
				if (result.status == 1) {

					$('#mainui #memberslist .table').children().each(function () {
						if ($(this).attr('member-id') == result.name) {
							$(this).hide(300);
						}
					});

					OCA.notification.onSuccess(
						"Member '" + result.name + "' successfully removed from the circle");
				}
				else
					OCA.notification.onFail(
						"Member '" + result.name + "' NOT removed from the circle: " +
						((result.error) ? result.error : 'no error message'));

			};


			this.joinCircleResult = function (result) {
				if (result.status == 1) {

					$('#mainui #memberslist .table').children().each(function () {
						if ($(this).attr('member-id') == result.name) {
							$(this).hide(300);
						}
					});

					if (result.member.level == 1) {
						OCA.notification.onSuccess(
							"You have successfully joined this circle");
					} else {
						OCA.notification.onSuccess(
							"You have requested an invitation to join this circle");
					}
					self.selectCircle(result.circle_id);

				}
				else
					OCA.notification.onFail(
						"Cannot join this circle: " +
						((result.error) ? result.error : 'no error message'));
			};

			this.leaveCircleResult = function (result) {
				if (result.status == 1) {

					$('#mainui #memberslist .table').children().each(function () {
						if ($(this).attr('member-id') == result.name)
							$(this).hide(300);
					});

					OCA.notification.onSuccess(
						"You have successfully left this circle");

					self.selectCircle(result.circle_id);
				}
				else {
					OCA.notification.onFail(
						"Cannot leave this circle: " +
						((result.error) ? result.error : 'no error message'));
				}
			};

			// getCurrentCircleTemplate: function (id) {
			//
			// 	currdiv = null;
			// 	$('#app-navigation.circles').children().each(function () {
			// 		if ($(this).attr('circle-id') == id) {
			// 			currdiv = $(this);
			// 			return false;
			// 		}
			// 	});
			// 	return currdiv;
			// }
		}
	};


	/**
	 * @constructs Notification
	 */
	var Notification = function () {
		this.initialize();
	};

	Notification.prototype = {

		initialize: function () {

			//noinspection SpellCheckingInspection
			var notyf = new Notyf({
				delay: 5000
			});

			this.onSuccess = function (text) {
				notyf.confirm(text);
			};

			this.onFail = function (text) {
				notyf.alert(text);
			};

		}

	};

	OCA.Circles.Navigation = Navigation;
	OCA.Circles.navigation = new Navigation();

	OCA.Notification = Notification;
	OCA.notification = new Notification();

});
