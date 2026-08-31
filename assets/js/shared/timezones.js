/*
 * Copyright (c) 2026 Right&Above, LLC
 * https://rightandabove.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

export const TZ_ALIASES = {
  'Europe/Kiev': 'Europe/Kyiv',
  'Asia/Calcutta': 'Asia/Kolkata',
  'Asia/Saigon': 'Asia/Ho_Chi_Minh',
  'Asia/Rangoon': 'Asia/Yangon',
  'Asia/Katmandu': 'Asia/Kathmandu',
  'Asia/Dacca': 'Asia/Dhaka',
  'Asia/Macao': 'Asia/Macau',
  'Asia/Thimbu': 'Asia/Thimphu',
  'Asia/Ashkhabad': 'Asia/Ashgabat',
  'Asia/Ujung_Pandang': 'Asia/Makassar',
  'Asia/Ulan_Bator': 'Asia/Ulaanbaatar',
  'Asia/Tel_Aviv': 'Asia/Jerusalem',
  'Asia/Istanbul': 'Europe/Istanbul',
  'Atlantic/Faeroe': 'Atlantic/Faroe',
  'America/Godthab': 'America/Nuuk',
  'America/Buenos_Aires': 'America/Argentina/Buenos_Aires',
  'America/Catamarca': 'America/Argentina/Catamarca',
  'America/Cordoba': 'America/Argentina/Cordoba',
  'America/Jujuy': 'America/Argentina/Jujuy',
  'America/Mendoza': 'America/Argentina/Mendoza',
  'America/Indianapolis': 'America/Indiana/Indianapolis',
  'America/Fort_Wayne': 'America/Indiana/Indianapolis',
  'America/Knox_IN': 'America/Indiana/Knox',
  'America/Louisville': 'America/Kentucky/Louisville',
  'America/Atka': 'America/Adak',
  'America/Coral_Harbour': 'America/Atikokan',
  'America/Ensenada': 'America/Tijuana',
  'America/Virgin': 'America/Port_of_Spain',
  'Australia/Currie': 'Australia/Hobart',
  'Australia/Yancowinna': 'Australia/Broken_Hill',
  'Pacific/Ponape': 'Pacific/Pohnpei',
  'Pacific/Truk': 'Pacific/Chuuk',
  'Pacific/Samoa': 'Pacific/Pago_Pago',
  'Pacific/Johnston': 'Pacific/Honolulu',
  'Africa/Asmera': 'Africa/Asmara',
  'Africa/Timbuktu': 'Africa/Abidjan',
};

export const EXCLUDED_TIMEZONES = new Set([
  'Europe/Minsk',
  'Europe/Kaliningrad', 'Europe/Moscow', 'Europe/Simferopol', 'Europe/Volgograd',
  'Europe/Kirov', 'Europe/Astrakhan', 'Europe/Saratov', 'Europe/Ulyanovsk', 'Europe/Samara',
  'Asia/Yekaterinburg', 'Asia/Omsk', 'Asia/Novosibirsk', 'Asia/Barnaul', 'Asia/Tomsk',
  'Asia/Novokuznetsk', 'Asia/Krasnoyarsk', 'Asia/Irkutsk', 'Asia/Chita', 'Asia/Yakutsk',
  'Asia/Khandyga', 'Asia/Vladivostok', 'Asia/Ust-Nera', 'Asia/Magadan', 'Asia/Sakhalin',
  'Asia/Srednekolymsk', 'Asia/Kamchatka', 'Asia/Anadyr',
  'Asia/Pyongyang',
]);

export function canonicalTimezone(tz) {
  const t = String(tz || '').trim();
  return TZ_ALIASES[t] || t;
}
