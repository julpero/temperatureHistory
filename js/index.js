//const mapColor = require('./map')

const DEFAULT_COLOR = { r: 0, g: 0, b: 0 }
const colorMapping = []

/**
 * Number to color converter with memoization
 * @param {number} num Integer number to convert
 * @param {number} states Maximal number value
 * @param {{r: number, b: number, g: number}} [defaultColor={ r: 0, g: 0, b: 0 }] A color
 * for incorrect nums
 * @returns {{r: number, b: number, g: number}} Color corresponds to num
 *
 * @example
 * import getColor from 'number-to-color'
 * const tenthColorOfSixteen = getColor(10, 16) // equals {r: 0, g: 64, b: 255}
 */
function numberToColor (num, states, defaultColor) {
  if (!colorMapping[states]) {
    colorMapping[states] = []
    for (let i = 0; i <= states; i += 1) {
      colorMapping[states].push(map(i / states))
    }
  }

  return colorMapping[states][num] || defaultColor || DEFAULT_COLOR
}